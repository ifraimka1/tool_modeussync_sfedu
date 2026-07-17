<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Declares supported Moodle activity features.
 *
 * @param string $feature Feature constant.
 * @return bool|null
 */
function modeussync_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        default:
            return null;
    }
}

/**
 * Hides the technical course-page link from users who cannot view the activity.
 *
 * @param cm_info $cm Course-module information.
 * @return void
 */
function modeussync_cm_info_dynamic(cm_info $cm): void {
    $context = context_module::instance($cm->id);
    if (!has_capability('mod/modeussync:view', $context)) {
        $cm->set_user_visible(false);
    }
}

/**
 * Creates the system-owned plugin row.
 *
 * @param stdClass $data Instance data.
 * @param moodleform|null $mform Form, unused for system creation.
 * @return int
 */
function modeussync_add_instance($data, $mform = null): int {
    global $DB;

    if (!\mod_modeussync\local\instance_guard::is_system_add_allowed()) {
        throw new moodle_exception('manualcreationdisabled', 'mod_modeussync');
    }
    if ($DB->record_exists('modeussync', ['course' => $data->course])) {
        throw new moodle_exception('singleinstanceonly', 'mod_modeussync');
    }

    $now = time();
    $record = (object) [
        'course' => $data->course,
        'name' => $data->name,
        'intro' => $data->intro ?? '',
        'introformat' => $data->introformat ?? FORMAT_HTML,
        'timecreated' => $now,
        'timemodified' => $now,
    ];

    return $DB->insert_record('modeussync', $record);
}

/**
 * Updates the existing row without allowing it to move or collide with another course instance.
 *
 * @param stdClass $data Instance data.
 * @param moodleform|null $mform Form.
 * @return bool
 */
function modeussync_update_instance($data, $mform = null): bool {
    global $DB;

    $instanceid = (int) ($data->instance ?? 0);
    $existing = $DB->get_record('modeussync', ['id' => $instanceid], '*', MUST_EXIST);
    if (isset($data->course) && (int) $data->course !== (int) $existing->course) {
        throw new moodle_exception('singleinstanceonly', 'mod_modeussync');
    }

    $duplicate = $DB->get_record('modeussync', ['course' => $existing->course], 'id');
    if ($duplicate && (int) $duplicate->id !== $instanceid) {
        throw new moodle_exception('singleinstanceonly', 'mod_modeussync');
    }

    $record = (object) [
        'id' => $instanceid,
        'name' => $data->name ?? $existing->name,
        'intro' => $data->intro ?? $existing->intro,
        'introformat' => $data->introformat ?? $existing->introformat,
        'timemodified' => time(),
    ];

    return $DB->update_record('modeussync', $record);
}

/**
 * Deletes an instance only from an explicit system path or full course deletion.
 *
 * @param int $id Instance id.
 * @return bool
 */
function modeussync_delete_instance($id): bool {
    global $DB, $SCRIPT;

    $systemdelete = \mod_modeussync\local\instance_guard::is_system_delete_allowed();
    $coursedelete = (isset($SCRIPT) && $SCRIPT === '/course/delete.php') ||
        modeussync_is_course_deletion_call();
    if (!$systemdelete && !$coursedelete) {
        throw new moodle_exception('manualdeletiondisabled', 'mod_modeussync');
    }
    if (!$DB->record_exists('modeussync', ['id' => $id])) {
        return false;
    }

    return $DB->delete_records('modeussync', ['id' => $id]);
}

/**
 * Detects the Moodle core course-content deletion stack independently of its entry point.
 *
 * @return bool
 */
function modeussync_is_course_deletion_call(): bool {
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
        if (isset($frame['function']) && in_array(
            $frame['function'],
            ['delete_course', 'remove_course_contents'],
            true
        )) {
            return true;
        }
    }

    return false;
}
