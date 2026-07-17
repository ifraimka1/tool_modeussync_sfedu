<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\event\assignments_queued;
use tool_modeussync\local\queue\course_status;
use tool_modeussync\local\queue\item_status;
use tool_modeussync\local\queue\queue_repository;
use tool_modeussync\local\queue\sync_response_ingestor;
use tool_modeussync\local\queue\target_module;

/**
 * Tests for ingesting new-course responses into the Modeus activity queue.
 */
class sync_response_ingestor_test extends advanced_testcase {

    /**
     * A successful response creates a queue and pending assign items.
     */
    public function test_ingest_creates_queue_and_items_with_assign_default(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $queues = (new sync_response_ingestor())->ingest($this->successful_response($course->id));
        $repository = new queue_repository();
        $items = $repository->get_items($queues[0]->id);

        $this->assertCount(1, $queues);
        $this->assertSame($course->id, (int) $queues[0]->courseid);
        $this->assertSame('modeus-course-1', $queues[0]->idmodeus);
        $this->assertSame(course_status::PENDING, $queues[0]->status);
        $this->assertCount(2, $items);
        $this->assertSame(target_module::ASSIGN, $items[0]->targetmodule);
        $this->assertSame(item_status::PENDING, $items[0]->status);
        $this->assertSame(25.0, (float) $items[0]->maxgrade);
    }

    /**
     * Repeating a response updates the same records instead of duplicating them.
     */
    public function test_repeated_response_does_not_duplicate_items(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $ingestor = new sync_response_ingestor();

        $firstqueues = $ingestor->ingest($this->successful_response($course->id));
        $firstqueues[0]->timemodified = time() - 10;
        $DB->update_record('tool_modeussync_course_queue', $firstqueues[0]);
        $secondqueues = $ingestor->ingest($this->successful_response($course->id));
        $repository = new queue_repository();
        $items = $repository->get_items($firstqueues[0]->id);
        $queue = $repository->get_course_queue($course->id);

        $this->assertSame([], $secondqueues);
        $this->assertCount(2, $items);
        $this->assertSame($firstqueues[0]->timemodified, (int) $queue->timemodified);
    }

    /**
     * A pending item receives changed source data without losing the editor selection.
     */
    public function test_pending_item_updates_name_and_grade_but_preserves_quiz_selection(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $ingestor = new sync_response_ingestor();
        $queue = $ingestor->ingest($this->successful_response($course->id))[0];
        $repository = new queue_repository();
        $items = $repository->get_items($queue->id);
        $repository->save_target_modules($queue->id, [$items[0]->id => target_module::QUIZ]);

        $response = $this->successful_response($course->id);
        $response['results'][0]['courseData'][0]['name'] = 'Обновлённая контрольная работа';
        $response['results'][0]['courseData'][0]['grade'] = 30;
        $ingestor->ingest($response);
        $item = $repository->get_item($items[0]->id);

        $this->assertSame('Обновлённая контрольная работа', $item->name);
        $this->assertSame(30.0, (float) $item->maxgrade);
        $this->assertSame(target_module::QUIZ, $item->targetmodule);
        $this->assertSame(item_status::PENDING, $item->status);
    }

    /**
     * An item already created in Moodle cannot be reopened by source repetition.
     */
    public function test_created_item_is_not_reopened_by_identical_response(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $ingestor = new sync_response_ingestor();
        $queue = $ingestor->ingest($this->successful_response($course->id))[0];
        $repository = new queue_repository();
        $item = $repository->get_items($queue->id)[0];
        $repository->mark_item_created($item->id, 42, $user->id, target_module::QUIZ);

        $ingestor->ingest($this->successful_response($course->id));
        $item = $repository->get_item($item->id);

        $this->assertSame(item_status::CREATED, $item->status);
        $this->assertSame(42, (int) $item->coursemoduleid);
        $this->assertSame($user->id, (int) $item->createdby);
        $this->assertSame(target_module::QUIZ, $item->targetmodule);
    }

    /**
     * Changed source diagnostics are retained without mutating a created activity binding.
     */
    public function test_created_item_updates_only_diagnostic_payload_and_reopens_synced_queue(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $ingestor = new sync_response_ingestor();
        $queue = $ingestor->ingest($this->successful_response($course->id))[0];
        $repository = new queue_repository();
        $items = $repository->get_items($queue->id);

        foreach ($items as $index => $item) {
            $repository->mark_item_created(
                $item->id,
                100 + $index,
                $user->id,
                $index === 0 ? target_module::QUIZ : target_module::ASSIGN
            );
        }
        $synctime = time() - 100;
        $repository->mark_course_synced($queue->id, $synctime);

        $firstitem = $repository->get_item($items[0]->id);
        $firstitem->timemodified = time() - 10;
        $DB->update_record('tool_modeussync_queue_items', $firstitem);
        $oldpayload = $firstitem->payloadjson;

        $response = $this->successful_response($course->id);
        $response['results'][0]['courseData'][0]['diagnostic'] = 'updated';
        $changedqueues = $ingestor->ingest($response);
        $updated = $repository->get_item($firstitem->id);
        $queue = $repository->get_course_queue($course->id);

        $this->assertCount(1, $changedqueues);
        $this->assertNotSame($oldpayload, $updated->payloadjson);
        $this->assertSame(item_status::CREATED, $updated->status);
        $this->assertSame(100, (int) $updated->coursemoduleid);
        $this->assertSame($user->id, (int) $updated->createdby);
        $this->assertSame(target_module::QUIZ, $updated->targetmodule);
        $this->assertSame('Контрольная работа', $updated->name);
        $this->assertSame(25.0, (float) $updated->maxgrade);
        $this->assertGreaterThan($firstitem->timemodified, $updated->timemodified);
        $this->assertSame(course_status::PENDING, $queue->status);
        $this->assertNull($queue->timesynced);
    }

    /**
     * Items omitted by a later response remain part of the queue state calculation.
     */
    public function test_omitted_pending_item_keeps_course_pending(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $ingestor = new sync_response_ingestor();
        $queue = $ingestor->ingest($this->successful_response($course->id))[0];
        $response = $this->successful_response($course->id);
        array_pop($response['results'][0]['courseData']);

        $ingestor->ingest($response);
        $repository = new queue_repository();
        $queue = $repository->get_course_queue($course->id);

        $this->assertSame(course_status::PENDING, $queue->status);
        $this->assertCount(2, $repository->get_items($queue->id));
    }

    /**
     * Only a byte-for-byte equivalent all-created response preserves sync completion.
     */
    public function test_identical_all_created_response_preserves_synced_state(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $ingestor = new sync_response_ingestor();
        $queue = $ingestor->ingest($this->successful_response($course->id))[0];
        $repository = new queue_repository();

        foreach ($repository->get_items($queue->id) as $item) {
            $repository->mark_item_created($item->id, $item->id + 100, $user->id, target_module::ASSIGN);
        }
        $synctime = time() - 50;
        $repository->mark_course_synced($queue->id, $synctime);

        $changedqueues = $ingestor->ingest($this->successful_response($course->id));
        $queue = $repository->get_course_queue($course->id);

        $this->assertSame([], $changedqueues);
        $this->assertSame(course_status::SYNCED, $queue->status);
        $this->assertSame($synctime, (int) $queue->timesynced);
    }

    /**
     * Omitting a previously created item is a non-identical response and reopens sync.
     */
    public function test_omitted_created_item_reopens_synced_queue(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $ingestor = new sync_response_ingestor();
        $queue = $ingestor->ingest($this->successful_response($course->id))[0];
        $repository = new queue_repository();

        foreach ($repository->get_items($queue->id) as $item) {
            $repository->mark_item_created($item->id, $item->id + 100, $user->id, target_module::ASSIGN);
        }
        $repository->mark_course_synced($queue->id, time() - 50);
        $response = $this->successful_response($course->id);
        array_pop($response['results'][0]['courseData']);

        $changedqueues = $ingestor->ingest($response);
        $queue = $repository->get_course_queue($course->id);

        $this->assertCount(1, $changedqueues);
        $this->assertSame(course_status::PENDING, $queue->status);
        $this->assertNull($queue->timesynced);
        $this->assertCount(2, $repository->get_items($queue->id));
    }

    /**
     * Generic state mutation cannot bypass the dedicated created transition.
     */
    public function test_generic_item_status_rejects_created_transition(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $queue = (new sync_response_ingestor())->ingest($this->successful_response($course->id))[0];
        $item = (new queue_repository())->get_items($queue->id)[0];

        $this->expectException(\invalid_parameter_exception::class);
        (new queue_repository())->set_item_status($item->id, item_status::CREATED);
    }

    /**
     * A created item cannot be reopened through the generic status method.
     */
    public function test_generic_item_status_cannot_reopen_created_item(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $queue = (new sync_response_ingestor())->ingest($this->successful_response($course->id))[0];
        $repository = new queue_repository();
        $item = $repository->get_items($queue->id)[0];
        $repository->mark_item_created($item->id, 42, $user->id, target_module::ASSIGN);

        $this->expectException(\invalid_parameter_exception::class);
        $repository->set_item_status($item->id, item_status::PENDING);
    }

    /**
     * Unsupported activity types cannot be persisted as created bindings.
     */
    public function test_mark_item_created_rejects_unsupported_module(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $queue = (new sync_response_ingestor())->ingest($this->successful_response($course->id))[0];
        $item = (new queue_repository())->get_items($queue->id)[0];

        $this->expectException(\invalid_parameter_exception::class);
        (new queue_repository())->mark_item_created($item->id, 42, $user->id, 'lesson');
    }

    /**
     * Sync completion can only be recorded through mark_course_synced().
     */
    public function test_generic_course_status_rejects_synced_transition(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $queue = (new sync_response_ingestor())->ingest($this->successful_response($course->id))[0];

        $this->expectException(\invalid_parameter_exception::class);
        (new queue_repository())->set_course_status($queue->id, course_status::SYNCED);
    }

    /**
     * A new item changes a previously synced queue back to pending.
     */
    public function test_new_item_reopens_synced_queue(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $ingestor = new sync_response_ingestor();
        $queue = $ingestor->ingest($this->successful_response($course->id))[0];
        $repository = new queue_repository();

        foreach ($repository->get_items($queue->id) as $item) {
            $repository->mark_item_created($item->id, $item->id + 100, $user->id, target_module::ASSIGN);
        }
        $repository->mark_course_synced($queue->id, time());

        $response = $this->successful_response($course->id);
        $response['results'][0]['courseData'][] = [
            'id' => 'meeting-3',
            'name' => 'Практическая работа',
            'grade' => 15,
        ];
        $queue = $ingestor->ingest($response)[0];

        $this->assertSame(course_status::PENDING, $queue->status);
        $this->assertNull($queue->timesynced);
        $this->assertCount(3, $repository->get_items($queue->id));
    }

    /**
     * A response without results is invalid.
     */
    public function test_missing_results_throws(): void {
        $this->resetAfterTest();

        $this->expectException(\UnexpectedValueException::class);
        (new sync_response_ingestor())->ingest([]);
    }

    /**
     * Course-level identifiers must name one whole Moodle course id.
     */
    public function test_non_integer_course_id_throws(): void {
        $this->resetAfterTest();
        $response = $this->successful_response(1);
        $response['results'][0]['id_lms'] = 1.5;

        $this->expectException(\UnexpectedValueException::class);
        (new sync_response_ingestor())->ingest($response);
    }

    /**
     * Invalid source data remains visible as a failed item and prevents sync completion.
     */
    public function test_invalid_item_is_stored_failed_and_blocks_sync(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $response = $this->successful_response($course->id);
        $response['results'][0]['courseData'][0]['grade'] = 0;

        $queue = (new sync_response_ingestor())->ingest($response)[0];
        $items = (new queue_repository())->get_items($queue->id);

        $this->assertSame(course_status::PENDING, $queue->status);
        $this->assertNull($queue->timesynced);
        $this->assertSame(item_status::FAILED, $items[0]->status);
        $this->assertNotEmpty($items[0]->lasterror);
        $this->assertSame(0.0, (float) $items[0]->maxgrade);
    }

    public function test_external_id_longer_than_course_module_limit_is_stored_failed(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $response = $this->successful_response($course->id);
        $response['results'][0]['courseData'] = [[
            'id' => str_repeat('x', 101),
            'name' => 'Задание с длинным идентификатором',
            'grade' => 25,
        ]];

        $queue = (new sync_response_ingestor())->ingest($response)[0];
        $items = (new queue_repository())->get_items($queue->id);

        $this->assertCount(1, $items);
        $this->assertSame(item_status::FAILED, $items[0]->status);
        $this->assertStringStartsWith('invalid:', $items[0]->externalid);
        $this->assertStringContainsString(str_repeat('x', 101), $items[0]->payloadjson);
        $this->assertNotEmpty($items[0]->lasterror);
    }

    public function test_external_id_longer_than_queue_column_is_hashed_without_dml_failure(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $response = $this->successful_response($course->id);
        $response['results'][0]['courseData'] = [[
            'id' => str_repeat('x', 300),
            'name' => 'Задание с очень длинным идентификатором',
            'grade' => 25,
        ]];

        $queue = (new sync_response_ingestor())->ingest($response)[0];
        $items = (new queue_repository())->get_items($queue->id);

        $this->assertCount(1, $items);
        $this->assertSame(item_status::FAILED, $items[0]->status);
        $this->assertLessThanOrEqual(255, strlen($items[0]->externalid));
        $this->assertStringStartsWith('invalid:', $items[0]->externalid);
        $this->assertStringContainsString(str_repeat('x', 300), $items[0]->payloadjson);
    }

    /**
     * Only course results with activities produce a queue event.
     */
    public function test_event_is_triggered_only_when_course_has_course_data(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $sink = $this->redirectEvents();
        $ingestor = new sync_response_ingestor();

        $emptyresponse = $this->successful_response($course->id);
        $emptyresponse['results'][0]['courseData'] = [];
        $ingestor->ingest($emptyresponse);
        $this->assertCount(0, $sink->get_events());

        $ingestor->ingest($this->successful_response($course->id));
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(assignments_queued::class, $events[0]);
        $this->assertSame($course->id, (int) $events[0]->get_context()->instanceid);
        $this->assertSame('modeus-course-1', $events[0]->other['idmodeus']);
        $this->assertSame(2, $events[0]->other['itemcount']);
    }

    /**
     * Builds the expected successful new-course response.
     *
     * @param int $courseid Moodle course id.
     * @return array
     */
    private function successful_response(int $courseid): array {
        return [
            'results' => [[
                'success' => true,
                'id_lms' => $courseid,
                'id_modeus' => 'modeus-course-1',
                'courseData' => [
                    [
                        'id' => 'meeting-1',
                        'name' => 'Контрольная работа',
                        'grade' => 25,
                    ],
                    [
                        'id' => 'meeting-2',
                        'name' => 'Итоговый тест',
                        'grade' => 75.5,
                    ],
                ],
            ]],
        ];
    }
}
