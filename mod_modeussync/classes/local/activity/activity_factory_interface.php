<?php

namespace mod_modeussync\local\activity;

defined('MOODLE_INTERNAL') || die();

/** Creates one supported Moodle activity from a persistent queue item. */
interface activity_factory_interface {

    /**
     * @param \stdClass $course Moodle course record.
     * @param int $sectionnum Course section number.
     * @param \stdClass $item Queue item.
     * @return int Moodle course_modules.id.
     */
    public function create(\stdClass $course, int $sectionnum, \stdClass $item): int;
}
