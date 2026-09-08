<?php

namespace mod_modeussync\local\global_sync;

use mod_modeussync\task\repeat_category_sync;

defined('MOODLE_INTERNAL') || die();

/** Validates and queues the global repeat-sync adhoc task. */
final class task_scheduler {

    public function queue(int $categoryid, bool $includesubcategories): void {
        if ($categoryid <= 0) {
            throw new \invalid_parameter_exception('Course category id must be a positive integer.');
        }
        \core_course_category::get($categoryid, MUST_EXIST, true);

        $task = new repeat_category_sync();
        $task->set_component('mod_modeussync');
        $task->set_custom_data((object) [
            'categoryid' => $categoryid,
            'includesubcategories' => $includesubcategories,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
