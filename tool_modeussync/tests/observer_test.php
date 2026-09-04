<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\repository\course_map_repository;

/** Tests course lifecycle integration owned by tool_modeussync. */
final class tool_modeussync_observer_test extends advanced_testcase {

    public function test_course_deletion_removes_mapping_without_a_queue(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repository = new course_map_repository();
        $repository->upsert((int) $course->id, 'rmup-1', 'prototype-1');
        $this->assertTrue(delete_course($course, false));
        $this->assertNull($repository->get_by_courseid((int) $course->id));
    }

    public function test_course_copy_restore_detaches_modeus_reference_from_summary(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $this->resetAfterTest();
        $source = $this->getDataGenerator()->create_course();
        $copy = $this->getDataGenerator()->create_course([
            'idnumber' => 'intentional-copy',
            'summary' => 'Описание до. Курс создан по РМУП [modeus-course-1] Описание после.',
        ]);
        $repository = new course_map_repository();
        $repository->upsert((int) $source->id, 'source-rmup', 'source-prototype');
        $repository->upsert((int) $copy->id, 'copy-rmup', 'copy-prototype');
        $event = \core\event\course_restored::create([
            'objectid' => $copy->id,
            'context' => context_course::instance($copy->id),
            'other' => [
                'type' => \backup::TYPE_1COURSE,
                'target' => \backup::TARGET_NEW_COURSE,
                'mode' => \backup::MODE_COPY,
                'operation' => \backup::OPERATION_RESTORE,
                'samesite' => true,
                'originalcourseid' => $source->id,
            ],
        ]);

        \tool_modeussync\observer::course_restored($event);

        $this->assertNull($repository->get_by_courseid((int) $copy->id));
        $this->assertSame('source-prototype', $repository->get_by_courseid((int) $source->id)->prototypeid);

        // A copied target must detach even if its summary no longer has an RMUP marker.
        $repository->upsert((int) $copy->id, 'copy-rmup', 'copy-prototype');
        \tool_modeussync\observer::course_restored($event);
        $this->assertNull($repository->get_by_courseid((int) $copy->id));

        $summary = $DB->get_field('course', 'summary', ['id' => $copy->id], MUST_EXIST);
        $this->assertStringNotContainsString('Курс создан по РМУП', $summary);
        $this->assertStringContainsString('Описание до.', $summary);
        $this->assertStringContainsString('Описание после.', $summary);
        $this->assertSame(
            'intentional-copy',
            $DB->get_field('course', 'idnumber', ['id' => $copy->id], MUST_EXIST)
        );
    }

    public function test_non_copy_restore_keeps_modeus_reference(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course([
            'summary' => 'Курс создан по РМУП [modeus-course-1]',
        ]);
        $repository = new course_map_repository();
        $repository->upsert((int) $course->id, 'modeus-course-1', 'prototype-1');
        $event = \core\event\course_restored::create([
            'objectid' => $course->id,
            'context' => context_course::instance($course->id),
            'other' => [
                'type' => \backup::TYPE_1COURSE,
                'target' => \backup::TARGET_NEW_COURSE,
                'mode' => \backup::MODE_GENERAL,
                'operation' => \backup::OPERATION_RESTORE,
                'samesite' => false,
            ],
        ]);

        \tool_modeussync\observer::course_restored($event);

        $this->assertSame(
            'Курс создан по РМУП [modeus-course-1]',
            $DB->get_field('course', 'summary', ['id' => $course->id], MUST_EXIST)
        );
        $this->assertSame('prototype-1', $repository->get_by_courseid((int) $course->id)->prototypeid);
    }
}
