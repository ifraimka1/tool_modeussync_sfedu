<?php

namespace tool_modeussync;

use tool_modeussync\local\queue\queue_repository;

defined('MOODLE_INTERNAL') || die();

/** Keeps persistent queue state aligned with Moodle course and activity deletion events. */
final class observer {

    public static function course_deleted(\core\event\course_deleted $event): void {
        (new queue_repository())->delete_course_queue((int) $event->objectid);
    }

    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        (new queue_repository())->reopen_item_by_course_module((int) $event->objectid);
    }
}
