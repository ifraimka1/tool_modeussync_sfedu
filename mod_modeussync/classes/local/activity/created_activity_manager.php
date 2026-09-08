<?php

namespace mod_modeussync\local\activity;

defined('MOODLE_INTERNAL') || die();

/** Performs narrowly scoped operations on activities already created from the Modeus queue. */
final class created_activity_manager {

    /** Returns whether the activity has at least one non-null final grade. */
    public function has_grades(int $courseid, int $cmid, string $modulename): bool {
        global $DB;

        $sql = "SELECT gg.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {grade_items} gi ON gi.courseid = cm.course
                                       AND gi.itemtype = 'mod'
                                       AND gi.itemmodule = m.name
                                       AND gi.iteminstance = cm.instance
                  JOIN {grade_grades} gg ON gg.itemid = gi.id
                 WHERE cm.id = :cmid
                   AND cm.course = :courseid
                   AND cm.deletioninprogress = 0
                   AND m.name = :modulename
                   AND gg.finalgrade IS NOT NULL";

        return $DB->record_exists_sql($sql, [
            'cmid' => $cmid,
            'courseid' => $courseid,
            'modulename' => $modulename,
        ]);
    }

    /** Updates only the module instance name and invalidates the course cache. */
    public function rename(int $courseid, int $cmid, string $modulename, string $name): void {
        global $CFG, $DB;

        $sql = "SELECT cm.instance
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.id = :cmid
                   AND cm.course = :courseid
                   AND cm.deletioninprogress = 0
                   AND m.name = :modulename";
        $instanceid = $DB->get_field_sql($sql, [
            'cmid' => $cmid,
            'courseid' => $courseid,
            'modulename' => $modulename,
        ], MUST_EXIST);

        $DB->set_field($modulename, 'name', $name, ['id' => $instanceid]);
        require_once($CFG->dirroot . '/course/lib.php');
        rebuild_course_cache($courseid, true);
    }

    /** Deletes the course module synchronously. */
    public function delete(int $cmid): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');
        if (!course_delete_module($cmid, false)) {
            throw new \moodle_exception('activitydeletionfailed', 'mod_modeussync');
        }
    }
}
