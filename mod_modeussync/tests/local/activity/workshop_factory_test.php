<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\activity\section_manager;
use mod_modeussync\local\activity\workshop_factory;

/** Tests creation of an empty Modeus workshop with the requested submission grade. */
final class workshop_factory_test extends advanced_testcase {

    public function test_factory_creates_workshop_with_submission_grade_and_no_assessment_grade(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/workshop/locallib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $sectionnum = (new section_manager())->get_or_create($course->id);
        $item = (object) [
            'externalid' => 'meeting-workshop-1',
            'name' => 'Проектный семинар',
            'maxgrade' => 48.5,
        ];

        $cmid = (new workshop_factory())->create($course, $sectionnum, $item);
        $cm = get_coursemodule_from_id('workshop', $cmid, $course->id, false, MUST_EXIST);
        $workshop = $DB->get_record('workshop', ['id' => $cm->instance], '*', MUST_EXIST);
        $submissiongradeitem = $DB->get_record('grade_items', [
            'courseid' => $course->id,
            'itemmodule' => 'workshop',
            'iteminstance' => $workshop->id,
            'itemnumber' => 0,
        ], '*', MUST_EXIST);

        $this->assertSame('meeting-workshop-1', $cm->idnumber);
        $this->assertSame('Проектный семинар', $workshop->name);
        $this->assertEquals(48.5, (float) $workshop->grade);
        $this->assertEquals(0.0, (float) $workshop->gradinggrade);
        $this->assertSame(workshop::PHASE_SETUP, (int) $workshop->phase);
        $this->assertEquals(48.5, (float) $submissiongradeitem->grademax);
    }
}
