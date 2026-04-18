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
        $allowUnenrolStudents = get_config('tool_modeussync', 'unenrol_students') === '1';
        $allowUnenrolTeachers = get_config('tool_modeussync', 'unenrol_teachers') === '1';
        $allowPersonUserDuplicates = get_config('tool_modeussync', 'allow_person_user_duplicates') === '1';
        mtrace("Текущая конфигурация:");
        mtrace("unenrol_students: " . var_export($allowUnenrolStudents, true));
        mtrace("unenrol_teachers: " . var_export($allowUnenrolTeachers, true));

        $courseMembersList = $this->lmsAdapterService->getCourseMembers($currentSession['id']);
        $enrolplugin = enrol_get_plugin('manual');
        $studentRoleId = $DB->get_record('role', ['shortname' => 'student'])->id;
        $teacherRoleId = $DB->get_record('role', ['shortname' => 'editingteacher'])->id;

        mtrace("Начинаем зачисление...");
        $totalEnroled = 0;
        $totalUnenroled = 0;
        $coursesWithMissingPersons = [];
        foreach ($courseMembersList as $courseMembers) {
            mtrace("");
            $courseId = $courseMembers['courseId'];
            $synchronizer = new CourseSynchronizer($enrolplugin, $courseMembers, $studentRoleId, $teacherRoleId);

            if ($synchronizer->loadAndValidateData()) {
                $synchronizer->syncMembers($allowPersonUserDuplicates, $allowUnenrolStudents, $allowUnenrolTeachers);

                $totalEnroled += $synchronizer->enroledCount;
                $totalUnenroled += $synchronizer->unenroledCount;

                if (count($synchronizer->missingStudentIds) + count($synchronizer->missingTeacherIds) > 0) {
                    $coursesWithMissingPersons[] = (object) [
                        'courseId' => $courseId,
                        'StudentExternalPersonIds' => $synchronizer->missingStudentIds,
                        'TeacherExternalPersonIds' => $synchronizer->missingTeacherIds,
                    ];
                }
            }
            mtrace(";");
        }

        mtrace("Всего зачислено $totalEnroled пользователей");
        mtrace("Всего отчислено $totalUnenroled пользователей");

        $allUsersFound = count($coursesWithMissingPersons) == 0;
        if (!$allUsersFound) {
            mtrace("* ОШИБКА: Не все пользователи были найдены по сквозному идентификатору. Проставьте идентификаторы и попробуйте снова.");
            $request = new \stdClass;
            $request->Courses = $coursesWithMissingPersons;
            $this->lmsAdapterService->saveMissingMembers($currentSession['id'], $request);
        }

        return $allUsersFound;
    }
}

class CourseSynchronizer
{
    public $enroledCount = 0; // сколько новых персон было зачислено на курсы
    public $unenroledCount = 0; // сколько новых персон было отчислено с курсов
    public $missingStudentIds = []; // студенты, для которых не был найден пользователь
    public $missingTeacherIds = []; // преподаватели, для которых не был найден пользователь
    private $enrolplugin = null;
    private $enrol = null; // сущность "списка зачисления" Moodle
    private $toInternalUserId = null; // словарь с внешнего id персоны на внутренний
    private $toExternalUserId = null; // словарь с внутреннего id персоны на внешний
    private $courseId = null; // id целевого курса
    private $studentIds = []; // студенты, которых нужно зачислить
    private $teacherIds = []; // преподаватели, которых нужно зачислить
    private $existingInternalIdSet = []; // внутренние id персон, зачисленные на курс до синхронизации 
    private $existingExternalIdSet = []; // внешние id персон, зачисленные на курс до синхронизации 
    private $actualExternalIdSet = []; // внешние id персон, которых не нужно отчислять с курса
    private $studentRoleId = null; // id роли студента
    private $studentRoleName = "Студент"; // имя роли студента для логирования
    private $teacherRoleId = null; // id роли преподавателя
    private $teacherRoleName = "Преподаватель"; // имя роли преподавателя для логирования

    public function __construct($enrolplugin, $courseMembers, $studentRoleId, $teacherRoleId)
    {
        $this->studentIds = $courseMembers['studentExternalPersonIds'];
        $this->teacherIds = $courseMembers['teacherExternalPersonIds'];
        $this->courseId = $courseMembers['courseId'];
        $this->enrolplugin = $enrolplugin;
        $this->studentRoleId = $studentRoleId;
        $this->teacherRoleId = $teacherRoleId;
    }

    public function loadAndValidateData(): bool
    {
        global $DB;
        $this->enrol = $DB->get_record('enrol', ['courseid' => $this->courseId, 'enrol' => 'manual']);
        if ($this->enrol == null) {
            mtrace("Не найдена системная запись об участниках курса $this->courseId. Скорее всего это ошибка в данных. Пропускаем курс.");
            return false;
        }

        $existingEnrolments = $DB->get_recordset('user_enrolments', ['enrolid' => $this->enrol->id]);
        foreach ($existingEnrolments as $enrolment) {
            $this->existingInternalIdSet[$enrolment->userid] = true;
        }
        $existingEnrolments->close();

        $users_repository = new users_repository();
        $this->toInternalUserId = $users_repository->toInternalIdMap_OneToMany(array_merge($this->studentIds, $this->teacherIds));
        $this->toExternalUserId = $users_repository->toExternalIdMap_OneToOne(array_keys($this->existingInternalIdSet));
        foreach (array_values($this->toExternalUserId) as $externalId) {
            $this->existingExternalIdSet[$externalId] = true;
        }

        return true;
    }

    public function syncMembers($allowPersonUserDuplicates, $allowUnenrolStudents, $allowUnenrolTeachers)
    {
        global $CFG, $DB;
        mtrace("Зачисляем на курс {$this->courseId}");

        foreach (array_intersect($this->studentIds, $this->teacherIds) as $duplicatedId) {
            mtrace("* ПРЕДУПРЕЖДЕНИЕ: персона '$duplicatedId' одновременно студент и преподаватель. Будет зачислена как преподаватель.");
            $key = array_search($duplicatedId, $this->studentIds);
            unset($this->studentIds[$key]);
        }

        foreach ($this->studentIds as $studentId) {
            $this->actualExternalIdSet[$studentId] = true;
            if (!$this->enrolPerson($studentId, $this->studentRoleId, $this->studentRoleName, $allowPersonUserDuplicates)) {
                $this->missingStudentIds[] = $studentId;
            }
        }
        foreach ($this->teacherIds as $teacherId) {
            $this->actualExternalIdSet[$teacherId] = true;
            if (!$this->enrolPerson($teacherId, $this->teacherRoleId, $this->teacherRoleName, $allowPersonUserDuplicates)) {
                $this->missingTeacherIds[] = $teacherId;
            }
        }

        mtrace("Отчисляем с курса {$this->courseId}");
        $this->unenrolPersons($allowUnenrolStudents, $allowUnenrolTeachers);
    }

    /**
     * @return bool - был ли найден пользователь
     */
    private function enrolPerson($externalId, $roleId, $roleName, $allowPersonUserDuplicates): bool
    {
        $internalIds = $this->toInternalUserId[$externalId] ?? [];

        if ($this->existingExternalIdSet[$externalId]) {
            $idstring = implode(', ', $internalIds);
            mtrace("Персона '$externalId' уже зачислена на курс, userid: $idstring");
            return true;
        }

        $internalId = null;
        if (count($internalIds) > 1) {
            $idstring = implode(',', $internalIds);
            mtrace("* ПРЕДУПРЕЖДЕНИЕ: для персоны $externalId было найдено больше одного пользователя userid:[$idstring]");
            if (!$allowPersonUserDuplicates) {
                mtrace("* ОШИБКА: Настройками плагина дублирование userid на одну персону запрещено. Персона $externalId будет пропущена.");
                return false;
            }
            $internalId = min($internalIds); // Важно: берем min идентификатор, чтобы зачислить более "старого" пользователя
        } else if (count($internalIds) == 1) {
            $internalId = $internalIds[0];
        } else if (count($internalIds) == 0) {
            mtrace("Пользователь для персоны $externalId (роль $roleName) не был найден");
            return false;
        }

        mtrace("Зачисляем персону '$externalId' как пользователя с id $internalId с ролью $roleName");
        $this->enrolplugin->enrol_user($this->enrol, $internalId, $roleId, 0, 0, null, false);
        $this->enroledCount += 1;
        return true;
    }

    private function unenrolPersons($allowUnenrolStudents, $allowUnenrolTeachers)
    {
        $courseContext = context_course::instance($this->courseId);
        foreach (array_keys($this->existingInternalIdSet) as $internalId) {
            $externalId = $this->toExternalUserId[$internalId];
            if ($externalId == null || $this->actualExternalIdSet[$externalId]) {
                continue;
            }
            $roles = get_user_roles($courseContext, $internalId, true);
            if (count($roles) > 1) {
                mtrace("Пропускаем отчисление (более одной роли) '$externalId' как пользователя с id '$internalId'");
                continue;
            }
            $role = $roles[key($roles)];
            $rolename = $role->shortname;

            if ($rolename == "student" && $allowUnenrolStudents) {
                mtrace("Отчисляем '$rolename' '$externalId' как пользователя с id '$internalId'");
                $this->enrolplugin->unenrol_user($this->enrol, $internalId);
                $this->unenroledCount += 1;
            } else if (($rolename == "editingteacher") && $allowUnenrolTeachers) {
                mtrace("Отчисляем '$rolename' '$externalId' как пользователя с id '$internalId'");
                $this->enrolplugin->unenrol_user($this->enrol, $internalId);
                $this->unenroledCount += 1;
            } else {
                mtrace("Пропускаем отчисление '$rolename' '$externalId' как пользователя с id '$internalId'");
            }
        }
    }
}