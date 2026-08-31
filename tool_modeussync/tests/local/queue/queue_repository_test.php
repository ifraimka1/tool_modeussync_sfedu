<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\local\queue\target_module;

/**
 * Tests for the persistent Modeus activity queue foundation.
 */
class queue_repository_test extends advanced_testcase {

    public function test_name_override_can_be_saved_and_cleared_for_pending_item(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repository = new \tool_modeussync\local\queue\queue_repository();
        $queue = $repository->upsert_course_queue($course->id, 'modeus-course');
        [$item] = $repository->upsert_item($queue->id, [
            'id' => 'activity-1',
            'name' => 'Modeus name',
            'grade' => 10,
        ]);

        $repository->save_name_overrides($queue->id, [$item->id => '  Moodle name  ']);
        $this->assertSame('Moodle name', $repository->get_item($item->id)->nameoverride);

        $repository->save_name_overrides($queue->id, [$item->id => " \t "]);
        $this->assertNull($repository->get_item($item->id)->nameoverride);
    }

    public function test_name_override_survives_source_refresh_and_created_items_are_immutable(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repository = new \tool_modeussync\local\queue\queue_repository();
        $queue = $repository->upsert_course_queue($course->id, 'modeus-course');
        [$item] = $repository->upsert_item($queue->id, [
            'id' => 'activity-1',
            'name' => 'Modeus name',
            'grade' => 10,
        ]);
        $repository->save_name_overrides($queue->id, [$item->id => 'Teacher name']);

        $repository->upsert_item($queue->id, [
            'id' => 'activity-1',
            'name' => 'Updated Modeus name',
            'grade' => 20,
        ]);
        $this->assertSame('Updated Modeus name', $repository->get_item($item->id)->name);
        $this->assertSame('Teacher name', $repository->get_item($item->id)->nameoverride);

        $repository->mark_item_created($item->id, 123, 2, target_module::ASSIGN);
        $repository->save_name_overrides($queue->id, [$item->id => 'Changed after creation']);
        $this->assertSame('Teacher name', $repository->get_item($item->id)->nameoverride);
    }

    public function test_name_override_rejects_foreign_items_and_overlong_values_without_partial_updates(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $repository = new \tool_modeussync\local\queue\queue_repository();
        $queue = $repository->upsert_course_queue($course->id, 'modeus-course');
        $otherqueue = $repository->upsert_course_queue($othercourse->id, 'other-course');
        [$item] = $repository->upsert_item($queue->id, ['id' => 'activity-1', 'name' => 'One', 'grade' => 10]);
        [$otheritem] = $repository->upsert_item($otherqueue->id, ['id' => 'activity-2', 'name' => 'Two', 'grade' => 10]);

        try {
            $repository->save_name_overrides($queue->id, [
                $item->id => 'Saved name',
                $otheritem->id => 'Foreign name',
            ]);
            $this->fail('Expected foreign queue item to be rejected.');
        } catch (\invalid_parameter_exception $exception) {
            $this->assertNull($repository->get_item($item->id)->nameoverride);
        }

        $this->expectException(\invalid_parameter_exception::class);
        $repository->save_name_overrides($queue->id, [$item->id => str_repeat('x', 256)]);
    }

    /**
     * Both queue tables exist and a course can have only one queue record.
     */
    public function test_tables_exist_and_course_queue_is_unique(): void {
        global $DB;

        $this->resetAfterTest();

        $this->assertTrue($DB->get_manager()->table_exists(new \xmldb_table('tool_modeussync_course_queue')));
        $this->assertTrue($DB->get_manager()->table_exists(new \xmldb_table('tool_modeussync_queue_items')));

        $course = $this->getDataGenerator()->create_course();
        $record = (object) [
            'courseid' => $course->id,
            'idmodeus' => 'modeus-course-1',
            'status' => 'pending',
            'timecreated' => time(),
            'timemodified' => time(),
        ];

        $DB->insert_record('tool_modeussync_course_queue', $record);

        $this->expectException(dml_write_exception::class);
        $DB->insert_record('tool_modeussync_course_queue', $record);
    }

    /**
     * Supported target modules and the default target module are stable.
     */
    public function test_target_module_support_and_default(): void {
        $this->assertTrue(target_module::is_supported(target_module::ASSIGN));
        $this->assertTrue(target_module::is_supported(target_module::QUIZ));
        $this->assertTrue(target_module::is_supported(target_module::WORKSHOP));
        $this->assertFalse(target_module::is_supported('lesson'));
        $this->assertSame(target_module::ASSIGN, target_module::DEFAULT);
    }
}
