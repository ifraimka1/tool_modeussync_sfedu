<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\service\LmsAdapterService;
use tool_modeussync\service\SyncService;
use tool_modeussync\task\pull_courses;
use tool_modeussync\local\queue\queue_repository;

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

    private array $failedCourseDeletionIds = [];

    public function set_lms_adapter_service(LmsAdapterService $service): void {
        $this->lmsAdapterService = $service;
    }

    public function set_sync_service(SyncService $service): void {
        $this->syncservice = $service;
    }

    public function create_for_test(array $courses, int $categoryid): array {
        return $this->create_courses($courses, $categoryid);
    }

    public function fail_course_deletion(int $courseid): void {
        $this->failedCourseDeletionIds[$courseid] = true;
    }

    protected function create_sync_service(): SyncService {
        return $this->syncservice;
    }

    protected function delete_duplicate_course(int $courseid): bool {
        if (isset($this->failedCourseDeletionIds[$courseid])) {
            return false;
        }

        return delete_course($courseid, false);
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
        $course = $DB->get_record('course', ['idnumber' => 'valid-modeus-id'], '*', MUST_EXIST);
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
            'idnumber' => 'valid-modeus-id',
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

        $course = $DB->get_record('course', ['idnumber' => 'valid-modeus-id'], '*', MUST_EXIST);
        $this->assertSame(1, $this->count_attendance_modules((int) $course->id));
    }

    public function test_existing_legacy_course_is_reused_by_modeus_id_in_summary(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'legacy-nonunique-id',
            'summary' => 'Курс создан по РМУП [valid-modeus-id]',
        ]);
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([$this->valid_prototype()], (int) $category->id);

        $this->assertFalse($result['failed']);
        $this->assertCount(1, $result['courses']);
        $this->assertSame((int) $course->id, $result['courses'][0]['id_lms']);
        $this->assertSame('valid-modeus-id', $result['courses'][0]['id_modeus']);
        $this->assertSame(
            'valid-modeus-id',
            $DB->get_field('course', 'idnumber', ['id' => $course->id], MUST_EXIST)
        );
        $this->assertSame(1, $DB->count_records('course', [
            'summary' => 'Курс создан по РМУП [valid-modeus-id]',
        ]));
    }

    public function test_oldest_existing_course_is_used_when_modeus_id_has_duplicates(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $summary = 'Курс создан по РМУП [valid-modeus-id]';
        $oldest = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'another-legacy-nonunique-id',
            'summary' => $summary,
        ]);
        $older = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'valid-modeus-id',
            'summary' => $summary,
        ]);
        $newer = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'legacy-nonunique-id',
            'summary' => $summary,
        ]);
        $DB->set_field('course', 'timecreated', 50, ['id' => $oldest->id]);
        $DB->set_field('course', 'timecreated', 100, ['id' => $older->id]);
        $DB->set_field('course', 'timecreated', 200, ['id' => $newer->id]);
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([$this->valid_prototype()], (int) $category->id);

        $this->assertFalse($result['failed']);
        $this->assertSame((int) $oldest->id, $result['courses'][0]['id_lms']);
        $this->assertTrue($DB->record_exists('course', ['id' => $oldest->id]));
        $this->assertFalse($DB->record_exists('course', ['id' => $older->id]));
        $this->assertFalse($DB->record_exists('course', ['id' => $newer->id]));
        $this->assertSame(
            'valid-modeus-id',
            $DB->get_field('course', 'idnumber', ['id' => $oldest->id], MUST_EXIST)
        );
        $this->assertSame(1, $DB->count_records('course', ['summary' => $summary]));
    }

    public function test_course_copy_restore_target_is_not_deleted_as_duplicate(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $summary = 'Курс создан по РМУП [valid-modeus-id]';
        $original = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'valid-modeus-id',
            'summary' => $summary,
        ]);
        $copy = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'intentional-copy',
            'summary' => $summary,
        ]);
        $DB->set_field('course', 'timecreated', 100, ['id' => $original->id]);
        $DB->set_field('course', 'timecreated', 200, ['id' => $copy->id]);
        $this->insert_copy_restore_controller((int) $copy->id);

        $result = (new testable_pull_courses_partial_failure())->create_for_test(
            [$this->valid_prototype()],
            (int) $category->id
        );

        $this->assertFalse($result['failed']);
        $this->assertSame((int) $original->id, $result['courses'][0]['id_lms']);
        $this->assertTrue($DB->record_exists('course', ['id' => $original->id]));
        $this->assertTrue($DB->record_exists('course', ['id' => $copy->id]));
    }

    public function test_lower_course_id_breaks_equal_timecreated_duplicate_tie(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $summary = 'Курс создан по РМУП [valid-modeus-id]';
        $lowerid = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'valid-modeus-id',
            'summary' => $summary,
        ]);
        $higherid = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'legacy-nonunique-id',
            'summary' => $summary,
        ]);
        $DB->set_field('course', 'timecreated', 200, ['id' => $lowerid->id]);
        $DB->set_field('course', 'timecreated', 200, ['id' => $higherid->id]);
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([$this->valid_prototype()], (int) $category->id);

        $this->assertFalse($result['failed']);
        $this->assertGreaterThan((int) $lowerid->id, (int) $higherid->id);
        $this->assertSame((int) $lowerid->id, $result['courses'][0]['id_lms']);
        $this->assertTrue($DB->record_exists('course', ['id' => $lowerid->id]));
        $this->assertFalse($DB->record_exists('course', ['id' => $higherid->id]));
    }

    public function test_duplicate_course_deletion_removes_modules_and_modeus_queue_records(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $summary = 'Курс создан по РМУП [valid-modeus-id]';
        $older = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'valid-modeus-id',
            'summary' => $summary,
        ]);
        $newer = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'legacy-nonunique-id',
            'summary' => $summary,
        ]);
        $DB->set_field('course', 'timecreated', 100, ['id' => $older->id]);
        $DB->set_field('course', 'timecreated', 200, ['id' => $newer->id]);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $newer->id,
            'name' => 'Duplicate course assignment',
        ]);
        $assignmoduleid = $DB->get_field('modules', 'id', ['name' => 'assign'], MUST_EXIST);
        $coursemoduleid = $DB->get_field('course_modules', 'id', [
            'course' => $newer->id,
            'module' => $assignmoduleid,
            'instance' => $assign->id,
        ], MUST_EXIST);
        $user = $this->getDataGenerator()->create_user();
        $repository = new queue_repository();
        $queue = $repository->upsert_course_queue((int) $newer->id, 'valid-modeus-id');
        [$item] = $repository->upsert_item((int) $queue->id, [
            'id' => 'duplicate-course-assignment',
            'name' => 'Duplicate course assignment',
            'grade' => 10,
        ]);
        $repository->mark_item_created((int) $item->id, (int) $coursemoduleid, (int) $user->id, 'assign');
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([$this->valid_prototype()], (int) $category->id);

        $this->assertFalse($result['failed']);
        $this->assertSame((int) $older->id, $result['courses'][0]['id_lms']);
        $this->assertTrue($DB->record_exists('course', ['id' => $older->id]));
        $this->assertFalse($DB->record_exists('course', ['id' => $newer->id]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $coursemoduleid]));
        $this->assertFalse($DB->record_exists('assign', ['id' => $assign->id]));
        $this->assertFalse($DB->record_exists('tool_modeussync_course_queue', ['id' => $queue->id]));
        $this->assertFalse($DB->record_exists('tool_modeussync_queue_items', ['id' => $item->id]));
    }

    public function test_idnumber_only_match_without_rmup_summary_is_not_deleted(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $idnumberonly = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'valid-modeus-id',
            'summary' => 'Курс создан вручную без ссылки на РМУП',
        ]);
        $summarymatch = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'legacy-nonunique-id',
            'summary' => 'Курс создан по РМУП [valid-modeus-id]',
        ]);
        $DB->set_field('course', 'timecreated', 300, ['id' => $idnumberonly->id]);
        $DB->set_field('course', 'timecreated', 200, ['id' => $summarymatch->id]);
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([$this->valid_prototype()], (int) $category->id);

        $this->assertFalse($result['failed']);
        $this->assertSame((int) $summarymatch->id, $result['courses'][0]['id_lms']);
        $this->assertTrue($DB->record_exists('course', ['id' => $idnumberonly->id]));
        $this->assertTrue($DB->record_exists('course', ['id' => $summarymatch->id]));
        $this->assertSame(
            'valid-modeus-id',
            $DB->get_field('course', 'idnumber', ['id' => $summarymatch->id], MUST_EXIST)
        );
    }

    public function test_failed_duplicate_deletion_does_not_block_other_courses_from_sync(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        set_config('default_category', $category->id, 'tool_modeussync');
        $summary = 'Курс создан по РМУП [valid-modeus-id]';
        $older = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'valid-modeus-id',
            'summary' => $summary,
        ]);
        $newer = $this->getDataGenerator()->create_course([
            'category' => $category->id,
            'idnumber' => 'legacy-nonunique-id',
            'summary' => $summary,
        ]);
        $DB->set_field('course', 'timecreated', 100, ['id' => $older->id]);
        $DB->set_field('course', 'timecreated', 200, ['id' => $newer->id]);
        $successfulprototype = $this->valid_prototype();
        $successfulprototype['id'] = 'successful-prototype-id';
        $successfulprototype['name'] = 'Successful course';
        $successfulprototype['summary'] = 'Курс создан по РМУП [successful-modeus-id]';
        $adapter = new pull_courses_test_lms_adapter_service();
        $adapter->courses = [$this->valid_prototype(), $successfulprototype];
        $syncservice = new pull_courses_test_sync_service();
        $task = new testable_pull_courses_partial_failure();
        $task->fail_course_deletion((int) $newer->id);
        $task->set_lms_adapter_service($adapter);
        $task->set_sync_service($syncservice);

        $result = $task->do_work(['id' => 'session-1'], null);

        $this->assertFalse($result);
        $this->assertTrue($DB->record_exists('course', ['id' => $older->id]));
        $this->assertTrue($DB->record_exists('course', ['id' => $newer->id]));
        $this->assertTrue($DB->record_exists('course', ['idnumber' => 'successful-modeus-id']));
        $this->assertCount(1, $syncservice->batches);
        $this->assertCount(1, $syncservice->batches[0]);
        $this->assertSame('successful-modeus-id', $syncservice->batches[0][0]['id_modeus']);
    }

    public function test_repeated_modeus_id_in_one_batch_creates_one_course(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $first = $this->valid_prototype();
        $second = $this->valid_prototype();
        $second['id'] = 'another-nonunique-prototype-id';
        $second['name'] = 'Another prototype for the same RMUP';
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([$first, $second], (int) $category->id);

        $this->assertFalse($result['failed']);
        $this->assertCount(2, $result['courses']);
        $this->assertSame($result['courses'][0]['id_lms'], $result['courses'][1]['id_lms']);
        $this->assertSame(1, $DB->count_records('course', ['idnumber' => 'valid-modeus-id']));
    }

    public function test_course_without_modeus_id_is_not_created(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $prototype = $this->valid_prototype();
        $prototype['summary'] = 'Описание без идентификатора РМУП';
        $task = new testable_pull_courses_partial_failure();

        $result = $task->create_for_test([$prototype], (int) $category->id);

        $this->assertTrue($result['failed']);
        $this->assertSame([], $result['courses']);
        $this->assertFalse($DB->record_exists('course', ['fullname' => 'Valid course']));
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
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'invalid-modeus-id']));
        $validcourse = $DB->get_record('course', ['idnumber' => 'valid-modeus-id'], '*', MUST_EXIST);
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
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'invalid-modeus-id']));
        $this->assertTrue($DB->record_exists('course', ['idnumber' => 'valid-modeus-id']));
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
        $course = $DB->get_record('course', ['idnumber' => 'chat-modeus-id'], '*', MUST_EXIST);
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

    private function insert_copy_restore_controller(int $courseid): void {
        global $DB, $USER;

        $now = time();
        $DB->insert_record('backup_controllers', (object) [
            'backupid' => md5('modeussync-copy-' . $courseid),
            'operation' => \backup::OPERATION_RESTORE,
            'type' => \backup::TYPE_1COURSE,
            'itemid' => $courseid,
            'format' => \backup::FORMAT_MOODLE,
            'interactive' => \backup::INTERACTIVE_NO,
            'purpose' => \backup::MODE_COPY,
            'userid' => $USER->id,
            'status' => \backup::STATUS_EXECUTING,
            'execution' => \backup::EXECUTION_DELAYED,
            'executiontime' => 0,
            'checksum' => md5('modeussync-copy-checksum-' . $courseid),
            'timecreated' => $now,
            'timemodified' => $now,
            'progress' => 0.5,
            'controller' => '',
        ]);
    }
}
