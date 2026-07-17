<?php

namespace mod_modeussync\local\activity;

use mod_modeussync\event\activity_created;
use mod_modeussync\event\activity_creation_failed;
use mod_modeussync\event\creation_started;
use mod_modeussync\event\queue_completed;
use mod_modeussync\event\sync_failed;
use mod_modeussync\event\sync_succeeded;
use tool_modeussync\local\queue\course_status;
use tool_modeussync\local\queue\item_status;
use tool_modeussync\local\queue\queue_repository;
use tool_modeussync\local\queue\target_module;
use tool_modeussync\service\SyncService;

defined('MOODLE_INTERNAL') || die();

/** Coordinates validation, reconciliation, partial creation, and unchanged `/sync`. */
final class creation_service {
    /** @var queue_repository */
    private $queues;

    /** @var factory_registry */
    private $factories;

    /** @var section_manager */
    private $sections;

    /** @var SyncService */
    private $syncservice;

    public function __construct(
        ?queue_repository $queues = null,
        ?factory_registry $factories = null,
        ?section_manager $sections = null,
        ?SyncService $syncservice = null
    ) {
        $this->queues = $queues ?? new queue_repository();
        $this->factories = $factories ?? new factory_registry();
        $this->sections = $sections ?? new section_manager();
        $this->syncservice = $syncservice ?? new SyncService();
    }

    public function process(int $courseid, int $userid, array $selections): \stdClass {
        global $DB;

        $queue = $this->queues->get_course_queue($courseid);
        if ($queue === null) {
            throw new \invalid_parameter_exception('The course has no Modeus assignment queue.');
        }
        $this->validate_selections($queue->id, $selections);

        $factory = \core\lock\lock_config::get_lock_factory('tool_modeussync');
        $lock = $factory->get_lock('queue:' . $courseid, 30);
        if (!$lock) {
            throw new \moodle_exception('cannotacquirecreationlock', 'mod_modeussync');
        }

        try {
            $queue = $this->queues->get_course_queue($courseid);
            if ($queue === null) {
                throw new \invalid_parameter_exception('The course has no Modeus assignment queue.');
            }
            $items = $this->queues->get_items($queue->id);
            $context = \context_course::instance($courseid);
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $items = $this->reconcile_created_items($courseid, $queue->id, $userid, $items);
            if ($queue->status === course_status::SYNCED && $this->all_created($items)) {
                return $this->result($queue->id, course_status::SYNCED, count($items), 0, false);
            }
            if (($queue->status === course_status::SYNC_FAILED ||
                    $queue->status === course_status::AWAITING_SYNC) && $this->all_created($items)) {
                $this->queues->set_course_status($queue->id, course_status::AWAITING_SYNC);
                return $this->perform_sync($queue, $course, $context, count($items));
            }

            $this->trigger_event(creation_started::class, [
                'objectid' => $queue->id,
                'context' => $context,
                'other' => ['itemcount' => count($items)],
            ]);

            $this->queues->save_target_modules($queue->id, $selections);
            $this->queues->set_course_status($queue->id, course_status::PROCESSING);
            $sectionnum = null;

            foreach ($this->queues->get_items($queue->id) as $item) {
                if ($item->status === item_status::CREATED) {
                    continue;
                }

                $createdcmid = null;
                try {
                    $existing = $this->find_existing_activity($courseid, $item->externalid);
                    if ($existing !== null && !target_module::is_supported($existing->modulename)) {
                        $error = get_string('activitytypeconflict', 'mod_modeussync');
                        $this->queues->set_item_status($item->id, item_status::FAILED, $error);
                        $this->trigger_event(activity_creation_failed::class, [
                            'objectid' => $item->id,
                            'context' => $context,
                            'other' => ['queueid' => $queue->id],
                        ]);
                        continue;
                    }

                    if ($existing !== null) {
                        $createdcmid = (int) $existing->id;
                        $this->queues->mark_item_created(
                            $item->id,
                            $createdcmid,
                            $userid,
                            $existing->modulename
                        );
                    } else {
                        $this->queues->set_item_status($item->id, item_status::PROCESSING);
                        if ($sectionnum === null) {
                            $sectionnum = $this->sections->get_or_create($courseid);
                        }
                        $createdcmid = $this->factories->get($item->targetmodule)
                            ->create($course, $sectionnum, $item);
                        $this->queues->mark_item_created(
                            $item->id,
                            $createdcmid,
                            $userid,
                            $item->targetmodule
                        );
                    }
                } catch (\Throwable $exception) {
                    $latest = $this->queues->get_item($item->id);
                    if ($latest->status !== item_status::CREATED) {
                        $safeerror = $this->safe_creation_error($item);
                        $this->queues->set_item_status($item->id, item_status::FAILED, $safeerror);
                        $this->trigger_event(activity_creation_failed::class, [
                            'objectid' => $item->id,
                            'context' => $context,
                            'other' => ['queueid' => $queue->id],
                        ]);
                    }
                    error_log(
                        '[mod_modeussync creation] item ' . (int) $item->id . ': ' .
                        get_class($exception)
                    );
                    continue;
                }

                $this->trigger_event(activity_created::class, [
                    'objectid' => $createdcmid,
                    'context' => $context,
                    'other' => [
                        'queueid' => $queue->id,
                        'itemid' => $item->id,
                    ],
                ]);
            }

            $items = $this->queues->get_items($queue->id);
            [$createdcount, $failedcount] = $this->count_results($items);
            if ($createdcount !== count($items)) {
                $this->queues->set_course_status($queue->id, course_status::PENDING);
                return $this->result($queue->id, course_status::PENDING, $createdcount, $failedcount, false);
            }

            $this->queues->set_course_status($queue->id, course_status::AWAITING_SYNC);
            $this->trigger_event(queue_completed::class, [
                'objectid' => $queue->id,
                'context' => $context,
                'other' => ['createdcount' => $createdcount],
            ]);

            return $this->perform_sync($queue, $course, $context, $createdcount);
        } finally {
            $lock->release();
        }
    }

    private function validate_selections(int $queueid, array $selections): void {
        $items = [];
        foreach ($this->queues->get_items($queueid) as $item) {
            $items[(int) $item->id] = $item;
        }

        foreach ($selections as $itemid => $modulename) {
            if (filter_var($itemid, FILTER_VALIDATE_INT) === false || (int) $itemid <= 0) {
                throw new \invalid_parameter_exception('Queue item ids must be positive integers.');
            }
            if (!isset($items[(int) $itemid])) {
                throw new \invalid_parameter_exception('Queue item does not belong to this course.');
            }
            if (!is_string($modulename) || !target_module::is_supported($modulename)) {
                throw new \invalid_parameter_exception('Unsupported target module.');
            }
        }
    }

    private function find_existing_activity(int $courseid, string $externalid): ?\stdClass {
        global $DB;

        $sql = "SELECT cm.id, m.name AS modulename
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course = :courseid
                   AND cm.idnumber = :externalid
                   AND cm.deletioninprogress = 0";
        $record = $DB->get_record_sql($sql, [
            'courseid' => $courseid,
            'externalid' => $externalid,
        ]);

        return $record === false ? null : $record;
    }

    /**
     * Verifies that queue rows marked created still have a real supported Moodle activity.
     *
     * @param int $courseid Moodle course id.
     * @param int $queueid Queue id.
     * @param int $userid User performing the reconciliation.
     * @param array $items Queue items.
     * @return array Fresh queue items.
     */
    private function reconcile_created_items(int $courseid, int $queueid, int $userid, array $items): array {
        foreach ($items as $item) {
            if ($item->status !== item_status::CREATED) {
                continue;
            }

            $existing = $this->find_existing_activity($courseid, $item->externalid);
            if ($existing === null) {
                $this->queues->reopen_created_item($item->id);
                continue;
            }
            if (!target_module::is_supported($existing->modulename)) {
                $this->queues->reopen_created_item(
                    $item->id,
                    item_status::FAILED,
                    get_string('activitytypeconflict', 'mod_modeussync')
                );
                continue;
            }
            if ((int) $item->coursemoduleid !== (int) $existing->id ||
                    $item->targetmodule !== $existing->modulename) {
                $this->queues->mark_item_created(
                    $item->id,
                    (int) $existing->id,
                    !empty($item->createdby) ? (int) $item->createdby : $userid,
                    $existing->modulename
                );
            }
        }

        return $this->queues->get_items($queueid);
    }

    private function all_created(array $items): bool {
        if (empty($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ($item->status !== item_status::CREATED) {
                return false;
            }
        }

        return true;
    }

    private function perform_sync(
        \stdClass $queue,
        \stdClass $course,
        \context_course $context,
        int $createdcount
    ): \stdClass {
        $idnumber = trim((string) $course->idnumber);

        try {
            if ($idnumber === '') {
                throw new \UnexpectedValueException('Moodle course idnumber is empty.');
            }
            $this->syncservice->send_sync_courses([[
                'id_modeus' => $queue->idmodeus,
                'id_lms' => $idnumber,
            ]]);
        } catch (\Throwable $exception) {
            $this->queues->set_course_status(
                $queue->id,
                course_status::SYNC_FAILED,
                get_string('syncfailed', 'mod_modeussync')
            );
            $this->trigger_event(sync_failed::class, [
                'objectid' => $queue->id,
                'context' => $context,
                'other' => ['createdcount' => $createdcount],
            ]);
            error_log(
                '[mod_modeussync sync] queue ' . (int) $queue->id . ': ' .
                get_class($exception)
            );

            return $this->result($queue->id, course_status::SYNC_FAILED, $createdcount, 0, true);
        }

        $this->queues->mark_course_synced($queue->id, time());
        $this->trigger_event(sync_succeeded::class, [
            'objectid' => $queue->id,
            'context' => $context,
            'other' => ['createdcount' => $createdcount],
        ]);

        return $this->result($queue->id, course_status::SYNCED, $createdcount, 0, true);
    }

    private function trigger_event(string $eventclass, array $data): void {
        try {
            $eventclass::create($data)->trigger();
        } catch (\Throwable $exception) {
            error_log(
                '[mod_modeussync audit] ' . $eventclass . ': ' .
                get_class($exception)
            );
        }
    }

    private function count_results(array $items): array {
        $createdcount = 0;
        $failedcount = 0;
        foreach ($items as $item) {
            if ($item->status === item_status::CREATED) {
                $createdcount++;
            } else if ($item->status === item_status::FAILED) {
                $failedcount++;
            }
        }

        return [$createdcount, $failedcount];
    }

    private function safe_creation_error(\stdClass $item): string {
        if ($item->targetmodule === target_module::ASSIGN &&
                floor((float) $item->maxgrade) !== (float) $item->maxgrade) {
            return get_string('assignfractionalgrade', 'mod_modeussync');
        }

        return get_string('activitycreationfailed', 'mod_modeussync', (object) [
            'name' => $item->name,
            'type' => $item->targetmodule,
        ]);
    }

    private function result(
        int $queueid,
        string $status,
        int $createdcount,
        int $failedcount,
        bool $syncattempted
    ): \stdClass {
        return (object) [
            'queueid' => $queueid,
            'status' => $status,
            'createdcount' => $createdcount,
            'failedcount' => $failedcount,
            'syncattempted' => $syncattempted,
        ];
    }
}
