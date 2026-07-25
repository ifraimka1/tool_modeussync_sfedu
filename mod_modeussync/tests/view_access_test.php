<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\access;
use mod_modeussync\local\activity\assign_factory;
use mod_modeussync\local\activity\creation_service;
use mod_modeussync\local\activity\section_manager;
use mod_modeussync\output\queue_page;
use tool_modeussync\local\queue\course_status;
use tool_modeussync\local\queue\item_status;
use tool_modeussync\local\queue\queue_repository;
use tool_modeussync\local\queue\target_module;

/** Access-control and safe view-model tests for the teacher page. */
final class view_access_test extends advanced_testcase {

    public function test_student_cannot_view(): void {
        [$course, $cm] = $this->course_module();
        $student = $this->enrolled_user($course->id, 'student');
        $this->setUser($student);
        $this->assertFalse(get_fast_modinfo($course, $student->id)->get_cm($cm->id)->uservisible);

        $this->expectException(required_capability_exception::class);
        access::require_view(context_module::instance($cm->id));
    }

    public function test_non_editing_teacher_cannot_manage(): void {
        [$course, $cm] = $this->course_module();
        $teacher = $this->enrolled_user($course->id, 'teacher');
        $this->setUser($teacher);

        $this->expectException(required_capability_exception::class);
        access::require_manage(context_module::instance($cm->id), $course->id);
    }

    public function test_editing_teacher_can_view_and_manage(): void {
        [$course, $cm] = $this->course_module();
        $teacher = $this->enrolled_user($course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->assertTrue(get_fast_modinfo($course, $teacher->id)->get_cm($cm->id)->uservisible);
        access::require_view(context_module::instance($cm->id));
        access::require_manage(context_module::instance($cm->id), $course->id);
        $this->addToAssertionCount(2);
    }

    public function test_create_request_without_valid_sesskey_is_rejected(): void {
        [$course, $cm] = $this->course_module();
        $teacher = $this->enrolled_user($course->id, 'editingteacher');
        $this->setUser($teacher);
        $oldpost = $_POST;
        $_POST['sesskey'] = 'invalid';

        try {
            access::require_create_request(context_module::instance($cm->id), $course->id);
            $this->fail('Expected invalid sesskey exception was not thrown.');
        } catch (moodle_exception $exception) {
            $this->assertSame('invalidsesskey', $exception->errorcode);
        } finally {
            $_POST = $oldpost;
        }
    }

    public function test_unsupported_target_module_is_rejected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'course-code']);
        $repository = new queue_repository();
        $queue = $repository->upsert_course_queue($course->id, 'modeus-course-1');
        [$item] = $repository->upsert_item($queue->id, [
            'id' => 'unsupported-1',
            'name' => 'Задание',
            'grade' => 25,
        ]);

        $this->expectException(invalid_parameter_exception::class);
        (new creation_service())->process($course->id, 2, [$item->id => 'lesson']);
    }

    public function test_output_rows_default_disable_link_and_escape_external_names(): void {
        global $PAGE;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'course-code']);
        $repository = new queue_repository();
        $queue = $repository->upsert_course_queue($course->id, 'modeus-course-1');
        $repository->upsert_item($queue->id, [
            'id' => 'pending-1',
            'name' => '<script>alert(1)</script> Ожидающее',
            'grade' => 25,
        ]);
        [$created] = $repository->upsert_item($queue->id, [
            'id' => 'created-1',
            'name' => 'Созданное задание',
            'grade' => 37,
        ]);
        $cmid = (new assign_factory())->create(
            $course,
            (new section_manager())->get_or_create($course->id),
            $created
        );
        $repository->mark_item_created($created->id, $cmid, 2, target_module::ASSIGN);
        $repository->set_course_status($queue->id, course_status::SYNC_FAILED, 'Safe retry message');
        $queue = $repository->get_course_queue($course->id);
        $items = $repository->get_items($queue->id);
        $PAGE->set_context(context_course::instance($course->id));

        $export = (new queue_page(
            $queue,
            $items,
            new moodle_url('/mod/modeussync/view.php', ['id' => 99]),
            true
        ))->export_for_template($PAGE->get_renderer('core'));
        $rows = [];
        foreach ($export->items as $row) {
            $rows[$row['id']] = $row;
        }

        $pending = $rows[$items[0]->id];
        $createdrow = $rows[$created->id];
        $this->assertTrue($pending['selectedassign']);
        $this->assertFalse($pending['disabled']);
        $this->assertStringNotContainsString('<script>', $pending['name']);
        $this->assertTrue($createdrow['disabled']);
        $this->assertStringContainsString('/mod/assign/view.php', $createdrow['activityurl']);
        $this->assertStringContainsString('id=' . $cmid, $createdrow['activityurl']);
        $this->assertSame(get_string('retrysync', 'mod_modeussync'), $export->buttonlabel);
        $this->assertFalse($export->buttondisabled);
    }

    public function test_output_uses_retry_creation_and_disables_synced_queue(): void {
        global $PAGE;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $repository = new queue_repository();
        $queue = $repository->upsert_course_queue($course->id, 'modeus-course-1');
        [$item] = $repository->upsert_item($queue->id, [
            'id' => 'failed-1',
            'name' => 'Задание с ошибкой',
            'grade' => 25,
        ]);
        $repository->set_item_status($item->id, item_status::FAILED, 'Safe creation error');
        $queue = $repository->get_course_queue($course->id);
        $item = $repository->get_item($item->id);
        $PAGE->set_context(context_course::instance($course->id));

        $failedexport = (new queue_page(
            $queue,
            [$item],
            new moodle_url('/mod/modeussync/view.php', ['id' => 99]),
            true
        ))->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame(get_string('retrycreation', 'mod_modeussync'), $failedexport->buttonlabel);
        $this->assertFalse($failedexport->buttondisabled);

        $cmid = (new assign_factory())->create(
            $course,
            (new section_manager())->get_or_create($course->id),
            $item
        );
        $repository->mark_item_created($item->id, $cmid, 2, target_module::ASSIGN);
        $syncedqueue = clone $queue;
        $syncedqueue->status = course_status::SYNCED;
        $createditem = $repository->get_item($item->id);
        $syncedexport = (new queue_page(
            $syncedqueue,
            [$createditem],
            new moodle_url('/mod/modeussync/view.php', ['id' => 99]),
            true
        ))->export_for_template($PAGE->get_renderer('core'));
        $this->assertTrue($syncedexport->buttondisabled);

        $missingitem = clone $createditem;
        $missingitem->coursemoduleid = PHP_INT_MAX;
        $missingexport = (new queue_page(
            $syncedqueue,
            [$missingitem],
            new moodle_url('/mod/modeussync/view.php', ['id' => 99]),
            true
        ))->export_for_template($PAGE->get_renderer('core'));
        $this->assertFalse($missingexport->buttondisabled);
        $this->assertSame(get_string('retrycreation', 'mod_modeussync'), $missingexport->buttonlabel);
    }

    /** @return array [course, cm]. */
    private function course_module(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('modeussync', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('modeussync', $instance->id, $course->id, false, MUST_EXIST);
        return [$course, $cm];
    }

    private function enrolled_user(int $courseid, string $archetype): stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roles = get_archetype_roles($archetype);
        $role = reset($roles);
        $this->getDataGenerator()->enrol_user($user->id, $courseid, $role->id);
        return $user;
    }
}
