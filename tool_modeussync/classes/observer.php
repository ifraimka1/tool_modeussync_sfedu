<?php

namespace tool_modeussync;

use tool_modeussync\local\course_reference;
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

    /** Detaches an intentional Moodle course copy from the source course's Modeus identity. */
    public static function course_restored(\core\event\course_restored $event): void {
        global $CFG, $DB;

        if (($event->other['mode'] ?? null) !== \backup::MODE_COPY) {
            return;
        }

        $course = $DB->get_record('course', ['id' => $event->objectid], 'id, summary', MUST_EXIST);
        $summary = course_reference::remove_modeus_reference((string) $course->summary);
        if ($summary === $course->summary) {
            return;
        }

        require_once($CFG->dirroot . '/course/lib.php');
        update_course((object) [
            'id' => (int) $course->id,
            'summary' => $summary,
        ]);
    }
}
