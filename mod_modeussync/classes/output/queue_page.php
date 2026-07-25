<?php

namespace mod_modeussync\output;

use tool_modeussync\local\queue\course_status;
use tool_modeussync\local\queue\item_status;
use tool_modeussync\local\queue\target_module;

defined('MOODLE_INTERNAL') || die();

/** Safe templatable projection of the persistent queue. */
final class queue_page implements \renderable, \templatable {
    /** @var \stdClass */
    private $queue;

    /** @var array */
    private $items;

    /** @var \moodle_url */
    private $actionurl;

    /** @var bool */
    private $canmanage;

    public function __construct(\stdClass $queue, array $items, \moodle_url $actionurl, bool $canmanage) {
        $this->queue = $queue;
        $this->items = $items;
        $this->actionurl = $actionurl;
        $this->canmanage = $canmanage;
    }

    public function export_for_template(\renderer_base $output): \stdClass {
        global $DB;

        $context = \context_course::instance((int) $this->queue->courseid);
        $rows = [];
        $hasfaileditems = false;
        $hasmissingcreateditems = false;

        foreach ($this->items as $item) {
            $hasfaileditems = $hasfaileditems || $item->status === item_status::FAILED;
            $activityurl = null;
            $activityexists = false;
            if (!empty($item->coursemoduleid) && target_module::is_supported($item->targetmodule)) {
                $sql = "SELECT cm.id
                          FROM {course_modules} cm
                          JOIN {modules} m ON m.id = cm.module
                         WHERE cm.id = :cmid
                           AND cm.course = :courseid
                           AND cm.deletioninprogress = 0
                           AND m.name = :modulename";
                $activityexists = $DB->record_exists_sql($sql, [
                    'cmid' => $item->coursemoduleid,
                    'courseid' => $this->queue->courseid,
                    'modulename' => $item->targetmodule,
                ]);
            }
            if ($item->status === item_status::CREATED && !$activityexists) {
                $hasmissingcreateditems = true;
            }
            if ($activityexists) {
                $activityurl = (new \moodle_url('/mod/' . $item->targetmodule . '/view.php', [
                    'id' => $item->coursemoduleid,
                ]))->out(false);
            }

            $rows[] = [
                'id' => (int) $item->id,
                'name' => format_string($item->name, true, ['context' => $context]),
                'maxgrade' => format_float((float) $item->maxgrade, 2),
                'statuslabel' => get_string('status_' . $item->status, 'mod_modeussync'),
                'selectedassign' => $item->targetmodule === target_module::ASSIGN,
                'selectedquiz' => $item->targetmodule === target_module::QUIZ,
                'disabled' => $item->status === item_status::CREATED || !$this->canmanage,
                'activityurl' => $activityurl,
                'error' => !empty($item->lasterror) ? (string) $item->lasterror : null,
            ];
        }

        if ($hasmissingcreateditems || $hasfaileditems) {
            $buttonkey = 'retrycreation';
        } else if ($this->queue->status === course_status::SYNC_FAILED ||
                $this->queue->status === course_status::AWAITING_SYNC) {
            $buttonkey = 'retrysync';
        } else {
            $buttonkey = 'createactivities';
        }
        return (object) [
            'actionurl' => $this->actionurl->out(false),
            'sesskey' => sesskey(),
            'items' => $rows,
            'canmanage' => $this->canmanage,
            'buttonlabel' => get_string($buttonkey, 'mod_modeussync'),
            'buttondisabled' => $this->queue->status === course_status::SYNCED &&
                !$hasmissingcreateditems,
            'queuestatus' => get_string('course_status_' . $this->queue->status, 'mod_modeussync'),
        ];
    }
}
