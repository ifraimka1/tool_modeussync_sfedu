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

    public function test_course_is_created_when_attendance_plugin_is_unavailable(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $attendancemodule = $DB->get_record('modules', ['name' => 'attendance']);
        if ($attendancemodule !== false) {
            $DB->set_field(
                'modules',
                'name',
                'attendance_test_off',
                ['id' => $attendancemodule->id]
            );
        }
        $this->assertFalse($DB->record_exists('modules', ['name' => 'attendance']));
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([$this->prototype_with_label()], (int) $category->id);

        $this->assertFalse($result['failed']);
        $this->assertCount(1, $result['courses']);
        $course = $DB->get_record('course', ['idnumber' => 'valid-course-id'], '*', MUST_EXIST);
        $this->assertSame('Valid course', $course->fullname);
        $this->assertSame('Valid course', $course->shortname);
        $this->assertSame('Курс создан по РМУП [valid-modeus-id]', $course->summary);
        $this->assertSame((int) $category->id, (int) $course->category);
        $this->assertSame('topics', $course->format);
        $this->assertSame(1, (int) $course->visible);
        $this->assertTrue($DB->record_exists('course_sections', [
            'course' => $course->id,
            'name' => 'Regular section',
        ]));
        $labelmoduleid = $DB->get_field('modules', 'id', ['name' => 'label'], MUST_EXIST);
        $this->assertTrue($DB->record_exists('course_modules', [
            'course' => $course->id,
            'module' => $labelmoduleid,
        ]));
    }

    public function test_new_course_gets_one_attendance_in_general_section(): void {
        $this->resetAfterTest();
        $this->require_attendance_plugin();
        $category = $this->getDataGenerator()->create_category();
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([$this->valid_prototype()], (int) $category->id);

        $courseid = (int) $result['courses'][0]['id_lms'];
        $this->assertSame(1, $this->count_attendance_modules($courseid, 0));
        $this->assertSame(1, $this->count_attendance_modules($courseid));
    }

    public function test_existing_course_without_attendance_gets_one_in_general_section(): void {
        $this->resetAfterTest();
        $this->require_attendance_plugin();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'valid-course-id',
        ]);
        $task = new testable_pull_courses_partial_failure();

        $task->create_for_test([$this->valid_prototype()], (int) $category->id);

        $this->assertSame(1, $this->count_attendance_modules((int) $course->id, 0));
        $this->assertSame(1, $this->count_attendance_modules((int) $course->id));
    }

    public function test_repeated_course_generation_does_not_duplicate_attendance(): void {
        global $DB;

        $this->resetAfterTest();
        $this->require_attendance_plugin();
        $category = $this->getDataGenerator()->create_category();
        $task = new testable_pull_courses_partial_failure();

        $task->create_for_test([$this->valid_prototype()], (int) $category->id);
        $task->create_for_test([$this->valid_prototype()], (int) $category->id);

        $course = $DB->get_record('course', ['idnumber' => 'valid-course-id'], '*', MUST_EXIST);
        $this->assertSame(1, $this->count_attendance_modules((int) $course->id));
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

    public function test_chat_prototype_is_skipped_without_failing_course_creation(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([$this->chat_prototype()], (int) $category->id);

        $this->assertFalse($result['failed']);
        $this->assertCount(1, $result['courses']);
        $course = $DB->get_record('course', ['idnumber' => 'chat-course-id'], '*', MUST_EXIST);
        $chatmodule = $DB->get_record('modules', ['name' => 'chat']);

        if ($chatmodule !== false) {
            $this->assertFalse($DB->record_exists('course_modules', [
                'course' => $course->id,
                'module' => $chatmodule->id,
            ]));
        }
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

    private function chat_prototype(): array {
        return [
            'id' => 'chat-course-id',
            'name' => 'Course with unsupported chat activity',
            'shortName' => 'chat-course-shortname',
            'summary' => 'Курс создан по РМУП [chat-modeus-id]',
            'sections' => [[
                'name' => 'Chat section',
                'modules' => [[
                    'id' => 'chat-module-id',
                    'name' => 'Unsupported chat activity',
                    'moduleTypeId' => 'chat',
                ]],
            ]],
        ];
    }

    private function prototype_with_label(): array {
        $prototype = $this->valid_prototype();
        $prototype['sections'] = [[
            'name' => 'Regular section',
            'modules' => [[
                'id' => 'valid-label-id',
                'name' => 'Regular label',
                'moduleTypeId' => 'label',
            ]],
        ]];

        return $prototype;
    }

    private function count_attendance_modules(int $courseid, ?int $sectionnum = null): int {
        global $DB;

        $params = [
            'courseid' => $courseid,
            'modulename' => 'attendance',
        ];
        $sectioncondition = '';
        if ($sectionnum !== null) {
            $sectioncondition = ' AND cs.section = :sectionnum';
            $params['sectionnum'] = $sectionnum;
        }

        return $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {course_sections} cs ON cs.id = cm.section
              WHERE cm.course = :courseid
                AND m.name = :modulename
                AND cm.deletioninprogress = 0
                    {$sectioncondition}",
            $params
        );
    }

    private function require_attendance_plugin(): void {
        global $DB;

        if (!$DB->record_exists('modules', ['name' => 'attendance'])) {
            $this->markTestSkipped('mod_attendance is not installed in the test Moodle instance');
        }
    }
}
