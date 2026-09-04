<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\activity\assign_factory;
use mod_modeussync\local\activity\quiz_factory;
use mod_modeussync\local\activity\section_manager;
use mod_modeussync\local\activity\workshop_factory;
use tool_modeussync\task\push_courses;
use tool_modeussync\repository\course_map_repository;

/** Verifies that generated graded activities remain exportable and the technical UI does not leak. */
final class push_courses_test extends advanced_testcase {

    public function test_mapping_and_course_windows_preserve_export_identity(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rmup-1']);
        (new course_map_repository())->upsert((int) $course->id, 'rmup-1', 'adapter-current');
        // Course time, map time, minimum cutoff, last sync, expected eligibility.
        $cases = [
            'map only' => [100, 300, 200, 250, true],
            'map below last sync' => [100, 249, 200, 250, false],
            'map at both cutoffs' => [100, 250, 250, 250, true],
            'map below minimum' => [100, 249, 250, 200, false],
            'no last sync' => [100, 250, 250, null, true],
            'no last sync below minimum' => [100, 249, 250, null, false],
            'course only' => [300, 100, 200, 250, true],
            'both recent' => [300, 300, 200, 250, true],
            'both old' => [100, 100, 200, 250, false],
        ];
        foreach ($cases as $label => [$coursetime, $maptime, $minimum, $lastsync, $expected]) {
            $DB->set_field('course', 'timecreated', $coursetime, ['id' => $course->id]);
            $DB->set_field('course', 'timemodified', $coursetime, ['id' => $course->id]);
            $DB->set_field('tool_modeussync_course_map', 'timemodified', $maptime, ['courseid' => $course->id]);
            $DB->delete_records('logstore_standard_log', ['courseid' => $course->id]);
            $rows = $this->courses_to_push($minimum, $lastsync);
            $matches = array_values(array_filter($rows, static function(array $row) use ($course): bool {
                return (int) $row['id'] === (int) $course->id;
            }));
            $this->assertCount($expected ? 1 : 0, $matches, $label);
            if ($expected) {
                $this->assertSame('adapter-current', $matches[0]['lmsIdNumber'], $label);
            }
        }
        $this->assertSame('rmup-1', $DB->get_field('course', 'idnumber', ['id' => $course->id]));
    }

    public function test_unmapped_course_keeps_existing_export_identity(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'manual-idnumber']);
        $byid = array_column($this->courses_to_push(0, null), null, 'id');
        $this->assertSame('manual-idnumber', $byid[$course->id]['lmsIdNumber']);
    }

    public function test_module_and_grade_events_still_select_courses_without_recent_course_or_map_changes(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $gradeitem = $DB->get_record('grade_items', ['courseid' => $course->id, 'itemtype' => 'course'], '*', MUST_EXIST);
        $DB->set_field('course', 'timecreated', 200, ['id' => $course->id]);
        $DB->set_field('course', 'timemodified', 200, ['id' => $course->id]);
        (new course_map_repository())->upsert((int) $course->id, 'rmup-1', 'prototype-1');
        $DB->set_field('tool_modeussync_course_map', 'timemodified', 100, ['courseid' => $course->id]);
        foreach (['course_modules' => $module->cmid, 'grade_items' => $gradeitem->id] as $table => $objectid) {
            $DB->delete_records('logstore_standard_log', ['courseid' => $course->id]);
            $this->assertArrayNotHasKey($course->id, array_column($this->courses_to_push(150, 250), null, 'id'));
            $context = context_course::instance($course->id);
            $DB->insert_record('logstore_standard_log', (object) [
                'eventname' => $table === 'course_modules' ? '\\core\\event\\course_module_updated' : '\\core\\event\\grade_item_updated',
                'component' => 'core', 'action' => 'updated',
                'target' => $table === 'course_modules' ? 'course_module' : 'grade_item',
                'objecttable' => $table, 'objectid' => $objectid,
                'crud' => 'u', 'edulevel' => 1, 'contextid' => $context->id,
                'contextlevel' => CONTEXT_COURSE, 'contextinstanceid' => $course->id,
                'userid' => 2, 'courseid' => $course->id, 'anonymous' => 0,
                'timecreated' => 300, 'origin' => 'cli',
            ]);
            $byid = array_column($this->courses_to_push(150, 250), null, 'id');
            $this->assertArrayHasKey($course->id, $byid, $table);
            $this->assertSame('prototype-1', $byid[$course->id]['lmsIdNumber']);
            $this->assertContains((int) $module->cmid, array_map('intval', array_column($byid[$course->id]['modules'], 'id')));
        }
    }

    private function courses_to_push(int $minimum, ?int $lastsync): array {
        $method = new ReflectionMethod(push_courses::class, 'getCoursesToPush');
        $method->setAccessible(true);
        return $method->invoke(new push_courses(), $minimum, $lastsync);
    }

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
