<?php

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\request\approved_contextlist;
use tool_modeussync\local\queue\queue_repository;
use tool_modeussync\privacy\provider;

/** Privacy API coverage for the createdby audit reference. */
final class tool_modeussync_privacy_provider_test extends advanced_testcase {

    public function test_context_discovery_and_user_deletion_clear_createdby_only(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $repository = new queue_repository();
        $queue = $repository->upsert_course_queue($course->id, 'modeus-course-1');
        [$item] = $repository->upsert_item($queue->id, [
            'id' => 'privacy-item',
            'name' => 'Задание',
            'grade' => 25,
        ]);
        $DB->set_field('tool_modeussync_queue_items', 'createdby', $user->id, ['id' => $item->id]);
        $context = context_course::instance($course->id);

        $contexts = provider::get_contexts_for_userid($user->id);
        $this->assertContains($context->id, $contexts->get_contextids());

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'tool_modeussync',
            [$context->id]
        ));

        $this->assertNull($DB->get_field('tool_modeussync_queue_items', 'createdby', ['id' => $item->id]));
        $this->assertTrue($DB->record_exists('tool_modeussync_queue_items', ['id' => $item->id]));
    }
}
