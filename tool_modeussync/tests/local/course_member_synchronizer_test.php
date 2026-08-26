<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\local\course_member_synchronizer;
use tool_modeussync\repository\users_repository;

/**
 * Tests synchronization of manual enrolments for one course.
 */
final class course_member_synchronizer_test extends advanced_testcase {

    public function test_enrols_members_and_teacher_role_wins_overlap(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('user_sync_field', 'user::idnumber', 'tool_modeussync');
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user(['idnumber' => 'student-1']);
        $teacher = $this->getDataGenerator()->create_user(['idnumber' => 'teacher-1']);
        $overlap = $this->getDataGenerator()->create_user(['idnumber' => 'both-roles']);
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

        $synchronizer = $this->create_synchronizer($course->id, [
            'studentExternalPersonIds' => ['student-1', 'both-roles'],
            'teacherExternalPersonIds' => ['teacher-1', 'both-roles'],
        ]);
        $this->assertTrue($synchronizer->load_and_validate_data());
        $synchronizer->sync_members(false, false, false);

        $context = context_course::instance($course->id);
        $this->assertTrue(user_has_role_assignment($student->id, $studentrole, $context->id));
        $this->assertTrue(user_has_role_assignment($teacher->id, $teacherrole, $context->id));
        $this->assertTrue(user_has_role_assignment($overlap->id, $teacherrole, $context->id));
        $this->assertFalse(user_has_role_assignment($overlap->id, $studentrole, $context->id));
        $this->assertSame(3, $synchronizer->enrolledcount);
        $this->assertSame([], $synchronizer->missingstudentids);
        $this->assertSame([], $synchronizer->missingteacherids);
    }

    public function test_existing_member_is_not_enrolled_or_counted_again(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('user_sync_field', 'user::idnumber', 'tool_modeussync');
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user(['idnumber' => 'student-1']);
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->enrol_user($enrol, $student->id, $studentrole);

        $synchronizer = $this->create_synchronizer($course->id, [
            'studentExternalPersonIds' => ['student-1'],
            'teacherExternalPersonIds' => [],
        ]);
        $this->assertTrue($synchronizer->load_and_validate_data());
        $synchronizer->sync_members(false, false, false);

        $this->assertSame(0, $synchronizer->enrolledcount);
    }

    public function test_duplicate_policy_selects_oldest_or_reports_missing(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('user_sync_field', 'user::idnumber', 'tool_modeussync');
        $allowedcourse = $this->getDataGenerator()->create_course();
        $deniedcourse = $this->getDataGenerator()->create_course();
        $alreadyenrolledcourse = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_user(['idnumber' => 'duplicate-person']);
        $second = $this->getDataGenerator()->create_user(['idnumber' => 'duplicate-person']);
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

        $allowed = $this->create_synchronizer($allowedcourse->id, [
            'studentExternalPersonIds' => ['duplicate-person'],
            'teacherExternalPersonIds' => [],
        ]);
        $this->assertTrue($allowed->load_and_validate_data());
        $allowed->sync_members(true, false, false);

        $allowedcontext = context_course::instance($allowedcourse->id);
        $oldestid = (int) min($first->id, $second->id);
        $newestid = (int) max($first->id, $second->id);
        $this->assertTrue(user_has_role_assignment($oldestid, $studentrole, $allowedcontext->id));
        $this->assertFalse(user_has_role_assignment($newestid, $studentrole, $allowedcontext->id));

        $denied = $this->create_synchronizer($deniedcourse->id, [
            'studentExternalPersonIds' => ['duplicate-person'],
            'teacherExternalPersonIds' => ['missing-teacher'],
        ]);
        $this->assertTrue($denied->load_and_validate_data());
        $denied->sync_members(false, false, false);

        $this->assertSame(['duplicate-person'], $denied->missingstudentids);
        $this->assertSame(['missing-teacher'], $denied->missingteacherids);
        $this->assertSame(0, $denied->enrolledcount);

        $alreadyenrolledinstance = $DB->get_record('enrol', [
            'courseid' => $alreadyenrolledcourse->id,
            'enrol' => 'manual',
        ], '*', MUST_EXIST);
        enrol_get_plugin('manual')->enrol_user($alreadyenrolledinstance, $first->id, $studentrole);
        $alreadyenrolled = $this->create_synchronizer($alreadyenrolledcourse->id, [
            'studentExternalPersonIds' => ['duplicate-person'],
            'teacherExternalPersonIds' => [],
        ]);
        $this->assertTrue($alreadyenrolled->load_and_validate_data());
        $alreadyenrolled->sync_members(false, false, false);
        $this->assertSame(['duplicate-person'], $alreadyenrolled->missingstudentids);
    }

    public function test_multi_role_and_unmapped_users_are_not_unenrolled(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('user_sync_field', 'user::idnumber', 'tool_modeussync');
        $course = $this->getDataGenerator()->create_course();
        $multirole = $this->getDataGenerator()->create_user(['idnumber' => 'multi-role']);
        $unmapped = $this->getDataGenerator()->create_user(['idnumber' => '']);
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('manual');
        $plugin->enrol_user($enrol, $multirole->id, $studentrole);
        role_assign($teacherrole, $multirole->id, context_course::instance($course->id)->id);
        $plugin->enrol_user($enrol, $unmapped->id, $studentrole);

        $synchronizer = $this->create_synchronizer($course->id, [
            'studentExternalPersonIds' => [],
            'teacherExternalPersonIds' => [],
        ]);
        $this->assertTrue($synchronizer->load_and_validate_data());
        $synchronizer->sync_members(false, true, true);

        $this->assertTrue($DB->record_exists('user_enrolments', [
            'enrolid' => $enrol->id,
            'userid' => $multirole->id,
        ]));
        $this->assertTrue($DB->record_exists('user_enrolments', [
            'enrolid' => $enrol->id,
            'userid' => $unmapped->id,
        ]));
        $this->assertSame(0, $synchronizer->unenrolledcount);
    }

    public function test_student_editing_teacher_and_legacy_teacher_unenrol_settings(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('user_sync_field', 'user::idnumber', 'tool_modeussync');
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user(['idnumber' => 'old-student']);
        $editingteacher = $this->getDataGenerator()->create_user(['idnumber' => 'old-editing-teacher']);
        $teacher = $this->getDataGenerator()->create_user(['idnumber' => 'old-teacher']);
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $editingteacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        $enrol = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('manual');
        $plugin->enrol_user($enrol, $student->id, $studentrole);
        $plugin->enrol_user($enrol, $editingteacher->id, $editingteacherrole);
        $plugin->enrol_user($enrol, $teacher->id, $teacherrole);

        $synchronizer = $this->create_synchronizer($course->id, [
            'studentExternalPersonIds' => [],
            'teacherExternalPersonIds' => [],
        ]);
        $this->assertTrue($synchronizer->load_and_validate_data());
        $synchronizer->sync_members(false, true, true);

        $this->assertFalse($DB->record_exists('user_enrolments', ['enrolid' => $enrol->id]));
        $this->assertSame(3, $synchronizer->unenrolledcount);
    }

    private function create_synchronizer(int $courseid, array $members): course_member_synchronizer {
        global $DB;

        $members['courseId'] = $courseid;
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

        return new course_member_synchronizer(
            enrol_get_plugin('manual'),
            $members,
            $studentrole,
            $teacherrole,
            new users_repository()
        );
    }
}
