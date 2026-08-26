<?php

namespace tool_modeussync\task;

use tool_modeussync\local\course_member_synchronizer;
use tool_modeussync\repository\users_repository;
use tool_modeussync\task\base\base_sync_job;

class pull_members extends base_sync_job
{
    public function get_name()
    {
        return 'pull_members';
    }

    public function do_work(array $currentSession, ?array $lastClosedSession): bool
    {
        global $CFG, $DB;
        require_once $CFG->libdir . '/accesslib.php';
        $unenrolStudents = get_config('tool_modeussync', 'unenrol_students') === '1';
        $unenrolTeachers = get_config('tool_modeussync', 'unenrol_teachers') === '1';
        $allowPersonUserDuplicates = get_config('tool_modeussync', 'allow_person_user_duplicates') === '1';
        mtrace("Текущая конфигурация:");
        mtrace("unenrol_students: " . var_export($unenrolStudents, true));
        mtrace("unenrol_teachers: " . var_export($unenrolTeachers, true));
        mtrace("allow_person_user_duplicates: " . var_export($allowPersonUserDuplicates, true));

        $courseMembersList = $this->lmsAdapterService->getCourseMembers($currentSession['id']);
        $enrolplugin = enrol_get_plugin('manual');
        $studentRoleId = $DB->get_record('role', ['shortname' => 'student'])->id;
        $teacherRoleId = $DB->get_record('role', ['shortname' => 'editingteacher'])->id;
        $users_repository = new users_repository();

        mtrace("Начинаем зачисление...");
        $totalEnroled = 0;
        $totalUnenroled = 0;
        $coursesWithMissingPersons = [];
        foreach ($courseMembersList as $courseMembers) {
            $courseId = (int) $courseMembers['courseId'];
            $synchronizer = new course_member_synchronizer(
                $enrolplugin,
                $courseMembers,
                (int) $studentRoleId,
                (int) $teacherRoleId,
                $users_repository
            );
            if ($synchronizer->load_and_validate_data()) {
                $synchronizer->sync_members(
                    $allowPersonUserDuplicates,
                    $unenrolStudents,
                    $unenrolTeachers
                );
                $totalEnroled += $synchronizer->enrolledcount;
                $totalUnenroled += $synchronizer->unenrolledcount;

                if (!empty($synchronizer->missingstudentids) || !empty($synchronizer->missingteacherids)) {
                    $coursesWithMissingPersons[] = (object) [
                        'courseId' => $courseId,
                        'StudentExternalPersonIds' => $synchronizer->missingstudentids,
                        'TeacherExternalPersonIds' => $synchronizer->missingteacherids,
                    ];
                }
            }

            mtrace("");
        }

        mtrace("Всего зачислено $totalEnroled пользователей");
        mtrace("Всего отчислено $totalUnenroled пользователей");

        if (!empty($coursesWithMissingPersons)) {
            mtrace("ОШИБКА: Не все пользователи были найдены по сквозному идентификатору. Проставьте идентификаторы и попробуйте снова.");
            $request = new \stdClass();
            $request->Courses = $coursesWithMissingPersons;
            $this->lmsAdapterService->saveMissingMembers($currentSession['id'], $request);
            return false;
        }

        return true;
    }
}
