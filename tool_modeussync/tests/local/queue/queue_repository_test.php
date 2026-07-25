<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\local\queue\target_module;

/**
 * Tests for the persistent Modeus activity queue foundation.
 */
class queue_repository_test extends advanced_testcase {

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
        $this->assertFalse(target_module::is_supported('lesson'));
        $this->assertSame(target_module::ASSIGN, target_module::DEFAULT);
    }
}
