<?php

namespace mod_modeussync\local\activity;

defined('MOODLE_INTERNAL') || die();

/** Locates or creates the dedicated Modeus assignment section. */
final class section_manager {
    private const SECTION_NAME = 'Задания из Modeus';

    public function get_or_create(int $courseid): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $existing = $DB->get_record('course_sections', [
            'course' => $courseid,
            'name' => self::SECTION_NAME,
        ], 'id, section');
        if ($existing) {
            return (int) $existing->section;
        }

        $lastsection = $DB->get_field_sql(
            'SELECT MAX(section) FROM {course_sections} WHERE course = :courseid',
            ['courseid' => $courseid]
        );
        $sectionnum = (int) $lastsection + 1;
        course_create_section($courseid, $sectionnum);
        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $sectionnum,
        ], '*', MUST_EXIST);
        $section->name = self::SECTION_NAME;
        $DB->update_record('course_sections', $section);
        rebuild_course_cache($courseid, true);

        return $sectionnum;
    }
}
