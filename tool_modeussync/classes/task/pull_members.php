<?php

namespace tool_modeussync\task;

use tool_modeussync\repository\users_repository;
use tool_modeussync\task\base\base_sync_job;
use context_course;

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
        mtrace("Текущая конфигурация:");
        mtrace("unenrol_students: " . var_export($unenrolStudents, true));
        mtrace("unenrol_teachers: " . var_export($unenrolTeachers, true));

        $courseMembersList = $this->lmsAdapterService->getCourseMembers($currentSession['id']);
        $enrolplugin = enrol_get_plugin('manual');
        $studentRoleId = $DB->get_record('role', ['shortname' => 'student'])->id;
        $teacherRoleId = $DB->get_record('role', ['shortname' => 'editingteacher'])->id;
        $users_repository = new users_repository();
        $userGetter = $users_repository->getUserIdGetter();
        $externalUserGetter = $users_repository->getUserExternalIdGetter();

        mtrace("Начинаем зачисление...");
        $totalEnroled = 0;
        $totalUnenroled = 0;
        $allUsersFound = true;
        foreach ($courseMembersList as $courseMembers) {
            $courseId = $courseMembers['courseId'];
            mtrace("Зачисляем на курс {$courseId}");
            $enrol = $DB->get_record('enrol', ['courseid' => $courseId, 'enrol' => 'manual']);
            if (!$enrol) {
                mtrace("Не найдена системная запись об участниках курса $courseId. Скорее всего это ошибка в данных. Пропускаем курс.");
                continue;
            }
            $courseContext = context_course::instance($courseId);
            $studentIds = $courseMembers['studentExternalPersonIds'];
            $teacherIds = $courseMembers['teacherExternalPersonIds'];
            $courseUserIds = [];
            foreach ($studentIds as $studentId) {
                $userId = $userGetter($studentId);
                if (!$userId) {
                    mtrace("Пользователь для студента $studentId не был найден");
                    $allUsersFound = false;
                    continue;
                }
                $courseUserIds[] = $userId;
                mtrace("Зачисляем студента '$studentId' как пользователя с id $userId");
                $enrolplugin->enrol_user($enrol, $userId, $studentRoleId);
                $totalEnroled += 1;
            }

            foreach ($teacherIds as $teacherId) {
                $userId = $userGetter($teacherId);
                if (!$userId) {
                    mtrace("Пользователь для преподавателя $teacherId не был найден");
                    $allUsersFound = false;
                    continue;
                }
                $courseUserIds[] = $userId;
                mtrace("Зачисляем преподавателя '$teacherId' как пользователя с id $userId");
                $enrolplugin->enrol_user($enrol, $userId, $teacherRoleId);
                $totalEnroled += 1;
            }

            mtrace("Отчисляем с курса {$courseId}");
            $participants = $DB->get_recordset('user_enrolments', array('enrolid' => $enrol->id));
            foreach ($participants as $participant) {
                $userId = $participant->userid;
                if (!!$userId && !in_array($userId, $courseUserIds)) {
                    $roles = get_user_roles($courseContext, $userId, true);
                    $role = key($roles);
                    $rolename = $roles[$role]->shortname;
                    $externalId = $externalUserGetter($userId);

                    if ($rolename == "student" && $unenrolStudents) {
                        mtrace("Отчисляем '$rolename' '$externalId' как пользователя с id '$userId'");
                        $enrolplugin->unenrol_user($enrol, $participant->userid);
                        $totalUnenroled += 1;
                    } else if (($rolename == "editingteacher" || $rolename == "teacher") && $unenrolTeachers) {
                        mtrace("Отчисляем '$rolename' '$externalId' как пользователя с id '$userId'");
                        $enrolplugin->unenrol_user($enrol, $participant->userid);
                        $totalUnenroled += 1;
                    } else {
                        mtrace("Пропускаем отчисление '$rolename' '$externalId' как пользователя с id '$userId'");
                    }
                }
            }
            $participants->close();

            mtrace("");
        }

        mtrace("Всего зачислено $totalEnroled пользователей");
        mtrace("Всего отчислено $totalUnenroled пользователей");

        if (!$allUsersFound) {
            mtrace("ОШИБКА: Не все пользователи были найдены по сквозному идентификатору. Проставьте идентификаторы и попробуйте снова.");
        }

        return $allUsersFound;
    }
}
