<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\global_sync\task_scheduler;

/** Tests safe and deduplicated creation of global repeat-sync adhoc tasks. */
final class task_scheduler_test extends advanced_testcase {

    public function test_queues_one_task_with_exact_custom_data_and_deduplicates_it(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $scheduler = new task_scheduler();

        $scheduler->queue((int) $category->id, true);
        $scheduler->queue((int) $category->id, true);

        $records = $DB->get_records('task_adhoc', [
            'classname' => '\\mod_modeussync\\task\\repeat_category_sync',
        ]);
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertSame('mod_modeussync', $record->component);
        $this->assertSame([
            'categoryid' => (int) $category->id,
            'includesubcategories' => true,
        ], json_decode($record->customdata, true));
    }

    public function test_rejects_missing_category_before_queue_changes(): void {
        global $DB;

        $this->resetAfterTest();
        $before = $DB->count_records('task_adhoc');

        try {
            (new task_scheduler())->queue(PHP_INT_MAX, false);
            $this->fail('Expected missing category exception was not thrown.');
        } catch (moodle_exception $exception) {
            $this->assertNotEmpty($exception->errorcode);
        }

        $this->assertSame($before, $DB->count_records('task_adhoc'));
    }
}
