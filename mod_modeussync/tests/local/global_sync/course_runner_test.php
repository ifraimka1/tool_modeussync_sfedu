<?php

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/adapter_modules_service.php');

use mod_modeussync\local\activity\assign_factory;
use mod_modeussync\local\activity\creation_service;
use mod_modeussync\local\activity\section_manager;
use mod_modeussync\local\global_sync\course_runner;
use tool_modeussync\local\queue\queue_repository;
use tool_modeussync\local\queue\target_module;

/** SyncService double that records every per-course request. */
final class global_sync_fake_service extends \tool_modeussync\service\SyncService {
    /** @var array */
    public $payloads = [];

    /** @var string[] */
    public $failingidnumbers = [];

    public function save_course_modules(string $externalid, array $coursedata): array {
        return (new modeussync_global_test_adapter_service())->saveCourseModules($externalid, $coursedata);
    }

    public function send_sync_courses(array $courses): array {
        $this->payloads[] = $courses;
        $externalid = (string) ($courses[0]['id_lms'] ?? '');
        if (in_array($externalid, $this->failingidnumbers, true)) {
            throw new RuntimeException('Deliberate global repeat-sync failure.');
        }

        return ['results' => [[
            'success' => true,
            'id_modeus' => $courses[0]['id_modeus'],
            'linked' => count($courses[0]['links']),
        ]]];
    }
}

/** Integration tests for sequential per-course repeat synchronization. */
final class course_runner_test extends advanced_testcase {

    public function test_sends_one_separate_sync_request_per_course(): void {
        $this->resetAfterTest();
        $first = $this->create_ready_course('course-code-1', 'modeus-course-1', 'assignment-1');
        $second = $this->create_ready_course('course-code-2', 'modeus-course-2', 'assignment-2');
        $syncservice = new global_sync_fake_service();
        $runner = new course_runner(new creation_service(null, null, null, $syncservice));

        $result = $runner->run([$first->id, $second->id]);

        $this->assertSame('modeus-course-1', $syncservice->payloads[0][0]['id_modeus']);
        $this->assertSame('prototype-course-code-1', $syncservice->payloads[0][0]['id_lms']);
        $this->assertSame('modeus-course-2', $syncservice->payloads[1][0]['id_modeus']);
        $this->assertSame('prototype-course-code-2', $syncservice->payloads[1][0]['id_lms']);
        $this->assertEquals((object) [
            'selected' => 2,
            'succeeded' => 2,
            'failed' => 0,
            'skipped' => 0,
        ], $result);
    }

    public function test_failure_and_missing_activity_do_not_stop_later_courses(): void {
        $this->resetAfterTest();
        $failed = $this->create_ready_course('course-fails', 'modeus-fails', 'assignment-fails');
        $pending = $this->create_pending_course('course-skips', 'modeus-skips', 'assignment-skips');
        $success = $this->create_ready_course('course-succeeds', 'modeus-succeeds', 'assignment-succeeds');
        $syncservice = new global_sync_fake_service();
        $syncservice->failingidnumbers = ['prototype-course-fails'];
        $runner = new course_runner(new creation_service(null, null, null, $syncservice));

        $result = $runner->run([$failed->id, $pending->id, $success->id]);

        $this->assertEquals((object) [
            'selected' => 3,
            'succeeded' => 1,
            'failed' => 1,
            'skipped' => 1,
        ], $result);
        $this->assertSame('prototype-course-fails', $syncservice->payloads[0][0]['id_lms']);
        $this->assertSame('prototype-course-succeeds', $syncservice->payloads[1][0]['id_lms']);
    }

    public function test_ignores_duplicate_and_nonpositive_course_ids(): void {
        $this->resetAfterTest();
        $course = $this->create_ready_course('course-once', 'modeus-once', 'assignment-once');
        $syncservice = new global_sync_fake_service();
        $runner = new course_runner(new creation_service(null, null, null, $syncservice));

        $result = $runner->run([0, $course->id, $course->id, -1]);

        $this->assertSame(1, $result->selected);
        $this->assertSame(1, $result->succeeded);
        $this->assertCount(1, $syncservice->payloads);
    }

    private function create_ready_course(string $idnumber, string $idmodeus, string $externalid): stdClass {
        $course = $this->create_pending_course($idnumber, $idmodeus, $externalid);
        $repository = new queue_repository();
        $queue = $repository->get_course_queue($course->id);
        $items = $repository->get_items($queue->id);
        $item = reset($items);
        $cmid = (new assign_factory())->create(
            $course,
            (new section_manager())->get_or_create($course->id),
            $item
        );
        $repository->mark_item_created($item->id, $cmid, 2, target_module::ASSIGN);

        return $course;
    }

    private function create_pending_course(string $idnumber, string $idmodeus, string $externalid): stdClass {
        $course = $this->getDataGenerator()->create_course(['idnumber' => $idnumber]);
        (new \tool_modeussync\repository\course_map_repository())->upsert(
            (int) $course->id,
            $idnumber,
            'prototype-' . $idnumber
        );
        $this->getDataGenerator()->create_module('modeussync', ['course' => $course->id]);
        $repository = new queue_repository();
        $queue = $repository->upsert_course_queue($course->id, $idmodeus);
        $repository->upsert_item($queue->id, [
            'id' => $externalid,
            'lesson_id' => 'lesson-' . $externalid,
            'lessonId' => 'lesson-' . $externalid,
            'typeCode' => 'HOMEWORK',
            'name' => 'Assignment ' . $externalid,
            'grade' => 25,
        ]);

        return $course;
    }
}
