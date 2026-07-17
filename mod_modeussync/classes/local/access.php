<?php

namespace mod_modeussync\local;

defined('MOODLE_INTERNAL') || die();

/** Central capability and sesskey boundary for the activity page. */
final class access {
    private function __construct() {
    }

    public static function require_view(\context_module $context): void {
        require_capability('mod/modeussync:view', $context);
    }

    public static function require_manage(\context_module $modulecontext, int $courseid): void {
        require_capability('mod/modeussync:manage', $modulecontext);
        require_capability('moodle/course:manageactivities', \context_course::instance($courseid));
    }

    public static function require_create_request(\context_module $modulecontext, int $courseid): void {
        require_sesskey();
        self::require_manage($modulecontext, $courseid);
    }

    public static function can_manage(\context_module $modulecontext, int $courseid): bool {
        return has_capability('mod/modeussync:manage', $modulecontext) &&
            has_capability('moodle/course:manageactivities', \context_course::instance($courseid));
    }
}
