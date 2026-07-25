<?php

namespace mod_modeussync\privacy;

defined('MOODLE_INTERNAL') || die();

/** Declares that the activity table itself stores no personal data. */
final class provider implements \core_privacy\local\metadata\null_provider {

    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
