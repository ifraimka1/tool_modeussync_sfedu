<?php

namespace mod_modeussync;

use mod_modeussync\local\instance_manager;
use tool_modeussync\event\assignments_queued;

defined('MOODLE_INTERNAL') || die();

/** Event bridge from persistent tool queue state to the system activity. */
final class observer {

    public static function assignments_queued(assignments_queued $event): void {
        try {
            (new instance_manager())->ensure_for_course((int) $event->courseid);
        } catch (\Throwable $exception) {
            error_log(
                '[mod_modeussync observer] Cannot ensure activity for course ' . (int) $event->courseid .
                ': ' . get_class($exception)
            );
        }
    }
}
