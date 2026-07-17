<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\local\queue\queue_repository;

/**
 * Test seam exposing SyncService response processing without running the cron task.
 */
final class testable_pull_courses extends \tool_modeussync\task\pull_courses {

    /**
     * @param array $response SyncService response.
     * @return array
     */
    public function process(array $response): array {
        return $this->process_sync_response($response);
    }
}

/**
 * Tests the manual-creation boundary in pull_courses.
 */
final class pull_courses_response_test extends advanced_testcase {

    /**
     * courseData is queued without creating graded activities; only the system UI may appear.
     */
    public function test_course_data_creates_queue_without_assign_or_quiz(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $queues = (new testable_pull_courses())->process($this->response($course->id));
        $queue = (new queue_repository())->get_course_queue($course->id);

        $this->assertCount(1, $queues);
        $this->assertNotNull($queue);
        $this->assertCount(2, (new queue_repository())->get_items($queue->id));
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course = :courseid
                   AND m.name IN (:assign, :quiz)";
        $this->assertFalse($DB->record_exists_sql($sql, [
            'courseid' => $course->id,
            'assign' => 'assign',
            'quiz' => 'quiz',
        ]));
        $this->assertTrue($DB->record_exists('modeussync', ['course' => $course->id]));
    }

    /**
     * A failed course result is rejected and cannot create queue data.
     */
    public function test_unsuccessful_result_throws(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $response = $this->response($course->id);
        $response['results'][0]['success'] = false;

        $this->expectException(\UnexpectedValueException::class);
        (new testable_pull_courses())->process($response);
    }

    /**
     * Empty courseData does not create a queue and reports no changes.
     */
    public function test_empty_course_data_creates_no_queue(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $response = $this->response($course->id);
        $response['results'][0]['courseData'] = [];

        $queues = (new testable_pull_courses())->process($response);

        $this->assertSame([], $queues);
        $this->assertNull((new queue_repository())->get_course_queue($course->id));
    }

    /**
     * A completely empty SyncService response is rejected before ingestion.
     */
    public function test_empty_response_throws_exact_error(): void {
        $this->resetAfterTest();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('SyncService response is empty');
        (new testable_pull_courses())->process([]);
    }

    /**
     * @param int $courseid Moodle course id.
     * @return array
     */
    private function response(int $courseid): array {
        return [
            'results' => [[
                'success' => true,
                'id_lms' => $courseid,
                'id_modeus' => 'modeus-course-1',
                'courseData' => [
                    ['id' => 'meeting-1', 'name' => 'Контрольная работа', 'grade' => 25],
                    ['id' => 'meeting-2', 'name' => 'Итоговый тест', 'grade' => 75.5],
                ],
            ]],
        ];
    }
}
