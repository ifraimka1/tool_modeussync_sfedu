<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\repository\course_map_repository;

/** Tests the current Adapter identity independently of course fields and queues. */
final class course_map_repository_test extends advanced_testcase {
    public function test_replacement_preserves_creation_time_and_unchanged_mapping_timestamp(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'rmup-1']);
        $repository = new course_map_repository();
        $first = $repository->upsert((int) $course->id, 'rmup-1', 'prototype-old');
        $DB->set_field('tool_modeussync_course_map', 'timecreated', 100, ['id' => $first->id]);
        $DB->set_field('tool_modeussync_course_map', 'timemodified', 100, ['id' => $first->id]);
        $second = $repository->upsert((int) $course->id, 'rmup-1', 'prototype-new');
        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame('prototype-new', $second->prototypeid);
        $this->assertSame(100, (int) $second->timecreated);
        $this->assertGreaterThan(100, (int) $second->timemodified);
        $this->assertSame(1, $DB->count_records('tool_modeussync_course_map'));
        $DB->set_field('tool_modeussync_course_map', 'timemodified', 101, ['id' => $second->id]);
        $unchanged = $repository->upsert((int) $course->id, 'rmup-1', 'prototype-new');
        $this->assertSame(101, (int) $unchanged->timemodified);
        $this->assertSame('rmup-1', $DB->get_field('course', 'idnumber', ['id' => $course->id]));
    }

    public function test_lookup_uses_mapping_rmup_and_is_keyed_by_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'edited']);
        $other = $this->getDataGenerator()->create_course();
        $repository = new course_map_repository();
        $repository->upsert((int) $other->id, 'rmup-other', 'prototype-other');
        $repository->upsert((int) $course->id, ' rmup-1 ', ' prototype-1 ');
        $maps = $repository->get_by_rmupids(['rmup-1']);
        $this->assertSame([(int) $course->id], array_keys($maps));
        $this->assertSame('prototype-1', $maps[$course->id]->prototypeid);
        $this->assertSame([], $repository->get_by_rmupids([]));
        $this->assertSame([], $repository->get_by_courseids([]));
        $this->assertSame('rmup-other', $repository->get_by_courseids([(int) $other->id])[$other->id]->rmupid);
        $repository->delete_by_courseid((int) $course->id);
        $repository->delete_by_courseid((int) $course->id);
        $this->assertNull($repository->get_by_courseid((int) $course->id));
        $this->assertNotNull($repository->get_by_courseid((int) $other->id));
    }

    public function test_invalid_identifiers_do_not_change_existing_mapping(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repository = new course_map_repository();
        $repository->upsert((int) $course->id, 'rmup-1', 'prototype-1');
        foreach ([['', 'valid'], ['valid', '  '], [str_repeat('r', 256), 'valid'],
                ['valid', str_repeat('p', 256)]] as [$rmupid, $prototypeid]) {
            try {
                $repository->upsert((int) $course->id, $rmupid, $prototypeid);
                $this->fail('Invalid identity must be rejected.');
            } catch (invalid_parameter_exception $exception) {
                $this->assertSame('prototype-1', $repository->get_by_courseid((int) $course->id)->prototypeid);
            }
        }
        $this->expectException(invalid_parameter_exception::class);
        $repository->upsert(0, 'rmup-1', 'prototype-1');
    }

    public function test_missing_course_cannot_acquire_mapping(): void {
        $this->resetAfterTest();
        $this->expectException(dml_missing_record_exception::class);
        (new course_map_repository())->upsert(2147483647, 'rmup-1', 'prototype-1');
    }

    public function test_mapping_rolls_back_with_caller_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $repository = new course_map_repository();
        $transaction = $DB->start_delegated_transaction();
        $repository->upsert((int) $course->id, 'rmup-1', 'prototype-1');
        $failure = new RuntimeException('Deliberate mapping rollback');
        try {
            $transaction->rollback($failure);
            $this->fail('Rollback must rethrow the supplied failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertNull($repository->get_by_courseid((int) $course->id));
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
    }
}
