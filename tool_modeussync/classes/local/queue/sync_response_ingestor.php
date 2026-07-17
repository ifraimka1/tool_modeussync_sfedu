<?php

namespace tool_modeussync\local\queue;

use tool_modeussync\event\assignments_queued;

defined('MOODLE_INTERNAL') || die();

/**
 * Converts new-course response data into persistent queue state.
 */
final class sync_response_ingestor {

    /** @var queue_repository */
    private $repository;

    /**
     * @param queue_repository|null $repository Queue DML boundary.
     */
    public function __construct(?queue_repository $repository = null) {
        $this->repository = $repository ?? new queue_repository();
    }

    /**
     * Ingests all course results from an unchanged new-course response.
     *
     * @param array $response SyncService response.
     * @return array Queue records represented by non-empty course results.
     */
    public function ingest(array $response): array {
        global $DB;

        if (!array_key_exists('results', $response) || !is_array($response['results'])) {
            throw new \UnexpectedValueException('The new-course response must contain results.');
        }

        $queues = [];
        foreach ($response['results'] as $result) {
            [$courseid, $idmodeus] = $this->validate_course_result($result);
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                throw new \UnexpectedValueException('The referenced Moodle course does not exist.');
            }

            $coursedata = $result['courseData'] ?? null;
            if (empty($coursedata)) {
                continue;
            }
            if (!is_array($coursedata)) {
                throw new \UnexpectedValueException('courseData must be an array when supplied.');
            }

            $lockfactory = \core\lock\lock_config::get_lock_factory('tool_modeussync');
            $lock = null;
            while ($lock === null || $lock === false) {
                $lock = $lockfactory->get_lock('queue:' . $courseid, 30);
                if (!$lock) {
                    mtrace('Modeus queue is busy for course ' . $courseid . '; waiting to persist the response.');
                }
            }

            $changed = false;
            try {
                $transaction = $DB->start_delegated_transaction();
                try {
                    $existingqueue = $this->repository->get_course_queue($courseid);
                    $changed = $existingqueue === null || $existingqueue->idmodeus !== $idmodeus;
                    $queue = $this->repository->upsert_course_queue($courseid, $idmodeus);
                    $responseitemids = [];
                    foreach ($coursedata as $item) {
                        if (!is_array($item)) {
                            throw new \UnexpectedValueException('Each courseData item must be an array.');
                        }
                        [$storeditem, $itemchanged] = $this->repository->upsert_item($queue->id, $item);
                        $responseitemids[(int) $storeditem->id] = true;
                        $changed = $changed || $itemchanged;
                    }

                    $allitems = $this->repository->get_items($queue->id);
                    $hasuncreateditems = false;
                    foreach ($allitems as $storeditem) {
                        if ($storeditem->status !== item_status::CREATED) {
                            $hasuncreateditems = true;
                        }
                        if (!isset($responseitemids[(int) $storeditem->id])) {
                            $changed = true;
                        }
                    }

                    $pendingtransitionneeded = $hasuncreateditems && (
                        $queue->status !== course_status::PENDING ||
                        ($queue->timesynced ?? null) !== null ||
                        ($queue->lasterror ?? null) !== null
                    );
                    $syncedresponsechanged = $queue->status === course_status::SYNCED && $changed;
                    if ($pendingtransitionneeded || $syncedresponsechanged) {
                        $this->repository->set_course_status($queue->id, course_status::PENDING);
                    }
                    $transaction->allow_commit();
                } catch (\Throwable $exception) {
                    $transaction->rollback($exception);
                }
            } finally {
                $lock->release();
            }

            $queue = $this->repository->get_course_queue($courseid);
            if ($changed) {
                $queues[] = $queue;
            }

            $event = assignments_queued::create([
                'objectid' => $queue->id,
                'context' => \context_course::instance($courseid),
                'other' => [
                    'idmodeus' => $idmodeus,
                    'itemcount' => count($coursedata),
                ],
            ]);
            $event->trigger();
        }

        return $queues;
    }

    /**
     * Validates the course-level result required for ingestion.
     *
     * @param mixed $result One SyncService result.
     * @return array [Moodle course id, Modeus course id].
     */
    private function validate_course_result($result): array {
        if (!is_array($result) || !array_key_exists('success', $result) || $result['success'] !== true) {
            throw new \UnexpectedValueException('The new-course result must be successful.');
        }

        $courseid = $result['id_lms'] ?? null;
        $idmodeus = $result['id_modeus'] ?? null;
        if (filter_var($courseid, FILTER_VALIDATE_INT) === false || (int) $courseid <= 0 ||
                !is_scalar($idmodeus) || trim((string) $idmodeus) === '') {
            throw new \UnexpectedValueException('The new-course result has invalid course identifiers.');
        }

        return [(int) $courseid, trim((string) $idmodeus)];
    }
}
