<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\service\LmsAdapterService;
use tool_modeussync\task\pull_members;

/** Test double replacing only LmsAdapter HTTP calls. */
final class pull_members_test_lms_adapter_service extends LmsAdapterService {
    public array $members = [];
    public array $missingrequests = [];

    public function __construct() {
    }

    public function getCourseMembers(string $sessionId) {
        return $this->members;
    }

    public function saveMissingMembers(string $sessionId, object $requestBody) {
        $this->missingrequests[] = $requestBody;
        return ['body' => []];
    }
}

/** Test seam for assigning the external service. */
final class testable_pull_members extends pull_members {
    public function set_lms_adapter_service(LmsAdapterService $service): void {
        $this->lmsAdapterService = $service;
    }
}

/** Tests task-level aggregation and reporting of missing course members. */
final class pull_members_test extends advanced_testcase {

    public function test_missing_members_are_aggregated_once_and_keep_session_open(): void {
        $this->resetAfterTest();
        set_config('user_sync_field', 'user::idnumber', 'tool_modeussync');
        set_config('allow_person_user_duplicates', '0', 'tool_modeussync');
        set_config('unenrol_students', '0', 'tool_modeussync');
        set_config('unenrol_teachers', '0', 'tool_modeussync');
        $firstcourse = $this->getDataGenerator()->create_course();
        $secondcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_user(['idnumber' => 'known-student']);

        $service = new pull_members_test_lms_adapter_service();
        $service->members = [
            [
                'courseId' => (int) $firstcourse->id,
                'studentExternalPersonIds' => ['known-student', 'missing-student'],
                'teacherExternalPersonIds' => [],
            ],
            [
                'courseId' => (int) $secondcourse->id,
                'studentExternalPersonIds' => [],
                'teacherExternalPersonIds' => ['missing-teacher'],
            ],
        ];
        $task = new testable_pull_members();
        $task->set_lms_adapter_service($service);

        $result = $task->do_work(['id' => 'session-1'], null);

        $this->assertFalse($result);
        $this->assertCount(1, $service->missingrequests);
        $this->assertEquals((object) [
            'Courses' => [
                (object) [
                    'courseId' => (int) $firstcourse->id,
                    'StudentExternalPersonIds' => ['missing-student'],
                    'TeacherExternalPersonIds' => [],
                ],
                (object) [
                    'courseId' => (int) $secondcourse->id,
                    'StudentExternalPersonIds' => [],
                    'TeacherExternalPersonIds' => ['missing-teacher'],
                ],
            ],
        ], $service->missingrequests[0]);
    }

    public function test_complete_membership_returns_success_without_missing_request(): void {
        $this->resetAfterTest();
        set_config('user_sync_field', 'user::idnumber', 'tool_modeussync');
        set_config('allow_person_user_duplicates', '0', 'tool_modeussync');
        set_config('unenrol_students', '0', 'tool_modeussync');
        set_config('unenrol_teachers', '0', 'tool_modeussync');
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_user(['idnumber' => 'known-student']);

        $service = new pull_members_test_lms_adapter_service();
        $service->members = [[
            'courseId' => (int) $course->id,
            'studentExternalPersonIds' => ['known-student'],
            'teacherExternalPersonIds' => [],
        ]];
        $task = new testable_pull_members();
        $task->set_lms_adapter_service($service);

        $this->assertTrue($task->do_work(['id' => 'session-1'], null));
        $this->assertSame([], $service->missingrequests);
    }
}
