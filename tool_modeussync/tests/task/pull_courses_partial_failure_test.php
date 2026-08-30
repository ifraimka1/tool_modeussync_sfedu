<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\service\LmsAdapterService;
use tool_modeussync\service\SyncService;
use tool_modeussync\task\pull_courses;

/** LmsAdapter test double replacing only external course retrieval. */
final class pull_courses_test_lms_adapter_service extends LmsAdapterService {
    public array $courses = [];

    public function __construct() {
    }

    public function getCoursesToCreate(string $sessionId): array {
        return $this->courses;
    }
}

/** SyncService test double returning a complete response for received courses. */
final class pull_courses_test_sync_service extends SyncService {
    public array $batches = [];

    public function send_created_courses(array $courses): array {
        $this->batches[] = $courses;
        return [
            'results' => array_map(static function(array $course): array {
                return [
                    'success' => true,
                    'id_lms' => $course['id_lms'],
                    'id_modeus' => $course['id_modeus'],
                    'courseData' => [],
                ];
            }, $courses),
        ];
    }
}

/** Test seam exposing course creation and external service injection. */
final class testable_pull_courses_partial_failure extends pull_courses {
    private SyncService $syncservice;

    public function set_lms_adapter_service(LmsAdapterService $service): void {
        $this->lmsAdapterService = $service;
    }

    public function set_sync_service(SyncService $service): void {
        $this->syncservice = $service;
    }

    public function create_for_test(array $courses, int $categoryid): array {
        return $this->create_courses($courses, $categoryid);
    }

    protected function create_sync_service(): SyncService {
        return $this->syncservice;
    }
}

/** Tests that one broken prototype does not block valid courses. */
final class pull_courses_partial_failure_test extends advanced_testcase {

    public function test_course_shortname_uses_full_course_name(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $prototype = $this->valid_prototype();
        $prototype['name'] = 'Full course name';
        $prototype['shortName'] = 'Adapter short name';

        (new testable_pull_courses_partial_failure())->create_for_test(
            [$prototype],
            (int) $category->id
        );

        $course = $DB->get_record('course', ['idnumber' => 'valid-course-id'], '*', MUST_EXIST);
        $this->assertSame('Full course name', $course->shortname);
    }

    public function test_course_shortname_uses_next_available_suffix(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $this->getDataGenerator()->create_course(['shortname' => 'Repeated course']);
        $this->getDataGenerator()->create_course(['shortname' => 'Repeated course 2']);
        $prototype = $this->valid_prototype();
        $prototype['name'] = 'Repeated course';

        (new testable_pull_courses_partial_failure())->create_for_test(
            [$prototype],
            (int) $category->id
        );

        $course = $DB->get_record('course', ['idnumber' => 'valid-course-id'], '*', MUST_EXIST);
        $this->assertSame('Repeated course 3', $course->shortname);
    }

    public function test_course_shortname_increments_existing_trailing_number(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $this->getDataGenerator()->create_course(['shortname' => 'Numbered course 7']);
        $prototype = $this->valid_prototype();
        $prototype['name'] = 'Numbered course 7';

        (new testable_pull_courses_partial_failure())->create_for_test(
            [$prototype],
            (int) $category->id
        );

        $course = $DB->get_record('course', ['idnumber' => 'valid-course-id'], '*', MUST_EXIST);
        $this->assertSame('Numbered course 8', $course->shortname);
    }

    public function test_course_shortname_suffix_stays_within_database_length(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $fullname = str_repeat('К', 254);
        $this->getDataGenerator()->create_course(['shortname' => $fullname]);
        $prototype = $this->valid_prototype();
        $prototype['name'] = $fullname;

        (new testable_pull_courses_partial_failure())->create_for_test(
            [$prototype],
            (int) $category->id
        );

        $course = $DB->get_record('course', ['idnumber' => 'valid-course-id'], '*', MUST_EXIST);
        $this->assertSame(str_repeat('К', 253) . ' 2', $course->shortname);
        $this->assertSame(255, core_text::strlen($course->shortname));
    }

    public function test_failed_course_rolls_back_and_next_course_is_created(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([
            $this->invalid_prototype(),
            $this->valid_prototype(),
        ], (int) $category->id);

        $this->assertTrue($result['failed']);
        $this->assertCount(1, $result['courses']);
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'invalid-course-id']));
        $validcourse = $DB->get_record('course', ['idnumber' => 'valid-course-id'], '*', MUST_EXIST);
        $this->assertSame((int) $validcourse->id, $result['courses'][0]['id_lms']);
    }

    public function test_do_work_processes_successful_courses_but_returns_false_after_partial_failure(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        set_config('default_category', $category->id, 'tool_modeussync');
        $adapter = new pull_courses_test_lms_adapter_service();
        $adapter->courses = [$this->invalid_prototype(), $this->valid_prototype()];
        $syncservice = new pull_courses_test_sync_service();
        $task = new testable_pull_courses_partial_failure();
        $task->set_lms_adapter_service($adapter);
        $task->set_sync_service($syncservice);

        $result = $task->do_work(['id' => 'session-1'], null);

        $this->assertFalse($result);
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'invalid-course-id']));
        $this->assertTrue($DB->record_exists('course', ['idnumber' => 'valid-course-id']));
        $this->assertCount(1, $syncservice->batches);
        $this->assertCount(1, $syncservice->batches[0]);
    }

    public function test_do_work_returns_true_when_all_courses_succeed(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        set_config('default_category', $category->id, 'tool_modeussync');
        $adapter = new pull_courses_test_lms_adapter_service();
        $adapter->courses = [$this->valid_prototype()];
        $syncservice = new pull_courses_test_sync_service();
        $task = new testable_pull_courses_partial_failure();
        $task->set_lms_adapter_service($adapter);
        $task->set_sync_service($syncservice);

        $this->assertTrue($task->do_work(['id' => 'session-1'], null));
        $this->assertCount(1, $syncservice->batches);
    }

    private function invalid_prototype(): array {
        return [
            'id' => 'invalid-course-id',
            'name' => 'Invalid course',
            'shortName' => 'invalid-course-shortname',
            'summary' => 'Курс создан по РМУП [invalid-modeus-id]',
            'sections' => [[
                'name' => 'Invalid section',
                'modules' => [[
                    'id' => 'invalid-module-id',
                    'name' => 'Invalid module',
                    'moduleTypeId' => 'modeus_missing_module_type',
                ]],
            ]],
        ];
    }

    private function valid_prototype(): array {
        return [
            'id' => 'valid-course-id',
            'name' => 'Valid course',
            'shortName' => 'valid-course-shortname',
            'summary' => 'Курс создан по РМУП [valid-modeus-id]',
            'sections' => [],
        ];
    }
}
