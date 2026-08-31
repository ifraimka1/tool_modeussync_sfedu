<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\activity\assign_factory;
use mod_modeussync\local\activity\quiz_factory;
use mod_modeussync\local\activity\section_manager;
use mod_modeussync\local\activity\workshop_factory;
use tool_modeussync\task\push_courses;

/** Verifies that generated graded activities remain exportable and the technical UI does not leak. */
final class push_courses_test extends advanced_testcase {

    public function test_export_contains_generated_activities_but_not_modeussync(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('modeussync', ['course' => $course->id]);
        $sectionnum = (new section_manager())->get_or_create($course->id);
        $assigncmid = (new assign_factory())->create($course, $sectionnum, (object) [
            'externalid' => 'meeting-1',
            'name' => 'Assignment',
            'maxgrade' => 25,
        ]);
        $quizcmid = (new quiz_factory())->create($course, $sectionnum, (object) [
            'externalid' => 'meeting-2',
            'name' => 'Quiz',
            'maxgrade' => 75,
        ]);
        $workshopcmid = (new workshop_factory())->create($course, $sectionnum, (object) [
            'externalid' => 'meeting-3',
            'name' => 'Workshop',
            'maxgrade' => 50,
        ]);

        $modules = (new push_courses())->getCourseModules($course);
        $bytype = [];
        foreach ($modules as $module) {
            if (in_array($module['moduleTypeId'], ['assign', 'quiz', 'workshop', 'modeussync'], true)) {
                $bytype[$module['moduleTypeId']][] = $module;
            }
        }

        $this->assertArrayNotHasKey('modeussync', $bytype);
        $this->assertSame([[
            'id' => $assigncmid,
            'lmsIdNumber' => 'meeting-1',
            'name' => 'Assignment',
            'moduleTypeId' => 'assign',
        ]], $bytype['assign']);
        $this->assertSame([[
            'id' => $quizcmid,
            'lmsIdNumber' => 'meeting-2',
            'name' => 'Quiz',
            'moduleTypeId' => 'quiz',
        ]], $bytype['quiz']);
        $this->assertSame([[
            'id' => $workshopcmid,
            'lmsIdNumber' => 'meeting-3',
            'name' => 'Workshop',
            'moduleTypeId' => 'workshop',
        ]], $bytype['workshop']);
    }

    public function test_module_types_include_generated_activity_types_but_not_modeussync(): void {
        $this->resetAfterTest();
        $types = (new push_courses())->get_module_types();
        $ids = array_column($types, 'id');

        $this->assertContains('assign', $ids);
        $this->assertContains('quiz', $ids);
        $this->assertContains('workshop', $ids);
        $this->assertNotContains('modeussync', $ids);
    }
}
