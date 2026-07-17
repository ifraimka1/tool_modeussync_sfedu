<?php

namespace mod_modeussync\local;

use mod_modeussync\local\activity\section_manager;
use tool_modeussync\local\queue\queue_repository;

defined('MOODLE_INTERNAL') || die();

/** Idempotently creates the single system activity for a queued course. */
final class instance_manager {
    /** @var queue_repository */
    private $queues;

    /** @var section_manager */
    private $sections;

    public function __construct(?queue_repository $queues = null, ?section_manager $sections = null) {
        $this->queues = $queues ?? new queue_repository();
        $this->sections = $sections ?? new section_manager();
    }

    public function ensure_for_course(int $courseid): \stdClass {
        global $CFG, $DB;

        if (!$this->queues->course_has_items($courseid)) {
            throw new \coding_exception('Cannot create mod_modeussync without queue items');
        }

        $factory = \core\lock\lock_config::get_lock_factory('mod_modeussync');
        $lock = $factory->get_lock('instance:' . $courseid, 10);
        if (!$lock) {
            throw new \moodle_exception('cannotacquirelock', 'mod_modeussync');
        }

        try {
            $existing = $DB->get_record('modeussync', ['course' => $courseid]);
            if ($existing) {
                return $existing;
            }

            require_once($CFG->dirroot . '/course/modlib.php');
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $module = $DB->get_record('modules', ['name' => 'modeussync'], '*', MUST_EXIST);
            $sectionnum = $this->sections->get_or_create($courseid);
            $moduleinfo = (object) [
                'course' => $courseid,
                'module' => $module->id,
                'modulename' => 'modeussync',
                'add' => 'modeussync',
                'name' => get_string('activityname', 'mod_modeussync'),
                'intro' => '',
                'introformat' => FORMAT_HTML,
                'section' => $sectionnum,
                'visible' => 1,
                'visibleoncoursepage' => 1,
                'cmidnumber' => '',
                'groupmode' => 0,
                'groupingid' => 0,
                'availability' => null,
                'completion' => 0,
                'showdescription' => 0,
            ];

            $created = instance_guard::run_system_add(
                static function() use ($moduleinfo, $course) {
                    return add_moduleinfo($moduleinfo, $course);
                }
            );

            return $DB->get_record('modeussync', ['id' => $created->instance], '*', MUST_EXIST);
        } finally {
            $lock->release();
        }
    }
}
