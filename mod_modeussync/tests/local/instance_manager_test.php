<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\instance_guard;
use mod_modeussync\local\instance_manager;
use tool_modeussync\event\assignments_queued;
use tool_modeussync\local\queue\queue_repository;

/** Tests the single, system-owned mod_modeussync instance boundary. */
final class instance_manager_test extends advanced_testcase {

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/modeussync/lib.php');
    }

    public function test_add_instance_rejects_manual_call(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('manualcreationdisabled', 'mod_modeussync'));
        modeussync_add_instance($this->instance_data($course->id));
    }

    public function test_system_guard_allows_first_instance(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instanceid = instance_guard::run_system_add(function() use ($course): int {
            return modeussync_add_instance($this->instance_data($course->id));
        });

        $this->assertTrue($DB->record_exists('modeussync', [
            'id' => $instanceid,
            'course' => $course->id,
        ]));
        $this->assertFalse(instance_guard::is_system_add_allowed());
    }

    public function test_unique_course_index_rejects_second_instance(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        instance_guard::run_system_add(function() use ($course): int {
            return modeussync_add_instance($this->instance_data($course->id));
        });

        $duplicate = $this->instance_data($course->id);
        $duplicate->timecreated = time();
        $duplicate->timemodified = time();
        $this->expectException(dml_write_exception::class);
        $DB->insert_record('modeussync', $duplicate);
    }

    public function test_update_instance_allows_same_record(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instanceid = instance_guard::run_system_add(function() use ($course): int {
            return modeussync_add_instance($this->instance_data($course->id));
        });
        $data = $this->instance_data($course->id);
        $data->instance = $instanceid;
        $data->name = 'Обновлённая синхронизация Modeus';

        $this->assertTrue(modeussync_update_instance($data));
        $this->assertSame($data->name, $DB->get_field('modeussync', 'name', ['id' => $instanceid]));
    }

    public function test_manual_delete_instance_is_rejected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('modeussync', ['course' => $course->id]);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('manualdeletiondisabled', 'mod_modeussync'));
        modeussync_delete_instance($instance->id);
    }

    public function test_editing_teacher_has_view_and_manage_but_not_addinstance(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $editingteacherroles = get_archetype_roles('editingteacher');
        $editingteacherrole = reset($editingteacherroles);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $editingteacherrole->id);

        $instance = $this->getDataGenerator()->create_module('modeussync', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('modeussync', $instance->id, $course->id, false, MUST_EXIST);
        $this->setUser($teacher);

        $this->assertTrue(has_capability('mod/modeussync:view', context_module::instance($cm->id)));
        $this->assertTrue(has_capability('mod/modeussync:manage', context_module::instance($cm->id)));
        $this->assertFalse(has_capability('mod/modeussync:addinstance', context_course::instance($course->id)));
    }

    public function test_assignments_queued_event_creates_exactly_one_activity(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $queue = $this->queue_items_without_event($course->id);

        $this->trigger_queue_event($course->id, $queue->id);
        $this->trigger_queue_event($course->id, $queue->id);

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'modeussync'], MUST_EXIST);
        $this->assertSame(1, $DB->count_records('modeussync', ['course' => $course->id]));
        $this->assertSame(1, $DB->count_records('course_modules', [
            'course' => $course->id,
            'module' => $moduleid,
        ]));
    }

    public function test_scheduled_task_restores_missing_instance(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->queue_items_without_event($course->id);

        (new \mod_modeussync\task\ensure_instances())->execute();

        $this->assertSame(1, $DB->count_records('modeussync', ['course' => $course->id]));
    }

    public function test_manager_rejects_course_without_queue_items(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        try {
            (new instance_manager())->ensure_for_course($course->id);
            $this->fail('Expected coding_exception was not thrown.');
        } catch (coding_exception $exception) {
            $this->assertStringContainsString('without queue items', $exception->getMessage());
        }
        $this->assertFalse($DB->record_exists('modeussync', ['course' => $course->id]));
    }

    public function test_created_instance_uses_modeus_section(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->queue_items_without_event($course->id);

        $instance = (new instance_manager())->ensure_for_course($course->id);
        $cm = get_coursemodule_from_instance('modeussync', $instance->id, $course->id, false, MUST_EXIST);
        $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);

        $this->assertSame('Задания из Modeus', $section->name);
    }

    public function test_two_sequential_ensure_calls_do_not_duplicate_instance(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->queue_items_without_event($course->id);
        $manager = new instance_manager();

        $first = $manager->ensure_for_course($course->id);
        $second = $manager->ensure_for_course($course->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $DB->count_records('modeussync', ['course' => $course->id]));
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'modeussync'], MUST_EXIST);
        $this->assertSame(1, $DB->count_records('course_modules', [
            'course' => $course->id,
            'module' => $moduleid,
        ]));
    }

    public function test_course_deletion_removes_system_instance_and_owned_queue(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $queue = $this->queue_items_without_event($course->id);
        (new instance_manager())->ensure_for_course($course->id);

        $this->assertTrue(delete_course($course, false));

        $this->assertFalse($DB->record_exists('modeussync', ['course' => $course->id]));
        $this->assertFalse($DB->record_exists('tool_modeussync_course_queue', ['id' => $queue->id]));
        $this->assertFalse($DB->record_exists('tool_modeussync_queue_items', ['queueid' => $queue->id]));
    }

    /**
     * @param int $courseid Course id.
     * @return stdClass
     */
    private function instance_data(int $courseid): stdClass {
        return (object) [
            'course' => $courseid,
            'name' => 'Задания из Modeus',
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ];
    }

    /**
     * Creates persistent queue state without publishing the observer event.
     *
     * @param int $courseid Course id.
     * @return stdClass
     */
    private function queue_items_without_event(int $courseid): stdClass {
        $repository = new queue_repository();
        $queue = $repository->upsert_course_queue($courseid, 'modeus-course-1');
        $repository->upsert_item($queue->id, [
            'id' => 'meeting-1',
            'name' => 'Контрольная работа',
            'grade' => 25,
        ]);

        return $queue;
    }

    /**
     * @param int $courseid Course id.
     * @param int $queueid Queue id.
     * @return void
     */
    private function trigger_queue_event(int $courseid, int $queueid): void {
        assignments_queued::create([
            'objectid' => $queueid,
            'context' => context_course::instance($courseid),
            'other' => [
                'idmodeus' => 'modeus-course-1',
                'itemcount' => 1,
            ],
        ])->trigger();
    }
}
