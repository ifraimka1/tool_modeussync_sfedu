<?php

namespace tool_modeussync\local;

use context_course;
use tool_modeussync\repository\users_repository;

/**
 * Synchronizes manual enrolments for one Moodle course.
 */
final class course_member_synchronizer {

    /** @var int Number of users newly enrolled by this run. */
    public int $enrolledcount = 0;

    /** @var int Number of users unenrolled by this run. */
    public int $unenrolledcount = 0;

    /** @var array External student ids without a usable Moodle user. */
    public array $missingstudentids = [];

    /** @var array External teacher ids without a usable Moodle user. */
    public array $missingteacherids = [];

    /** @var mixed Manual enrolment plugin. */
    private $enrolplugin;

    /** @var object|null Moodle enrol instance. */
    private ?object $enrol = null;

    /** @var array External id => Moodle user ids. */
    private array $tointernaluserid = [];

    /** @var array Moodle user id => external id. */
    private array $toexternaluserid = [];

    /** @var int Target Moodle course id. */
    private int $courseid;

    /** @var array Requested student external ids. */
    private array $studentids;

    /** @var array Requested teacher external ids. */
    private array $teacherids;

    /** @var array Set of Moodle user ids enrolled before synchronization. */
    private array $existinginternalidset = [];

    /** @var array Set of external ids enrolled before synchronization. */
    private array $existingexternalidset = [];

    /** @var array Set of external ids present in the requested membership. */
    private array $actualexternalidset = [];

    /** @var int Moodle student role id. */
    private int $studentroleid;

    /** @var int Moodle editing teacher role id. */
    private int $teacherroleid;

    /** @var users_repository User mapping repository. */
    private users_repository $usersrepository;

    /**
     * @param mixed $enrolplugin Manual enrolment plugin.
     * @param array $coursemembers LmsAdapter membership payload for one course.
     * @param int $studentroleid Moodle student role id.
     * @param int $teacherroleid Moodle editing teacher role id.
     * @param users_repository $usersrepository User mapping repository.
     */
    public function __construct(
        $enrolplugin,
        array $coursemembers,
        int $studentroleid,
        int $teacherroleid,
        users_repository $usersrepository
    ) {
        $this->studentids = array_values(array_unique($coursemembers['studentExternalPersonIds'] ?? []));
        $this->teacherids = array_values(array_unique($coursemembers['teacherExternalPersonIds'] ?? []));
        $this->courseid = (int) $coursemembers['courseId'];
        $this->enrolplugin = $enrolplugin;
        $this->studentroleid = $studentroleid;
        $this->teacherroleid = $teacherroleid;
        $this->usersrepository = $usersrepository;
    }

    /**
     * Loads the course enrolment and user-id maps.
     *
     * @return bool Whether the course can be synchronized.
     */
    public function load_and_validate_data(): bool {
        global $DB;

        $this->enrol = $DB->get_record('enrol', [
            'courseid' => $this->courseid,
            'enrol' => 'manual',
        ]);
        if (!$this->enrol) {
            mtrace("Не найдена системная запись об участниках курса {$this->courseid}. Скорее всего это ошибка в данных. Пропускаем курс.");
            return false;
        }

        $existing = $DB->get_recordset('user_enrolments', ['enrolid' => $this->enrol->id]);
        try {
            foreach ($existing as $enrolment) {
                $this->existinginternalidset[(int) $enrolment->userid] = true;
            }
        } finally {
            $existing->close();
        }

        $requestedids = array_merge($this->studentids, $this->teacherids);
        $this->tointernaluserid = $this->usersrepository->toInternalIdMap_OneToMany($requestedids);
        $this->toexternaluserid = $this->usersrepository->toExternalIdMap_OneToOne(
            array_keys($this->existinginternalidset)
        );
        foreach ($this->toexternaluserid as $externalid) {
            if ($externalid !== null && $externalid !== '') {
                $this->existingexternalidset[(string) $externalid] = true;
            }
        }

        return true;
    }

    /**
     * Applies requested enrolments and configured removals.
     *
     * @param bool $allowduplicates Whether one external id may match multiple Moodle users.
     * @param bool $unenrolstudents Whether absent students may be unenrolled.
     * @param bool $unenrolteachers Whether absent teachers may be unenrolled.
     */
    public function sync_members(bool $allowduplicates, bool $unenrolstudents, bool $unenrolteachers): void {
        mtrace("Зачисляем на курс {$this->courseid}");

        $overlappingids = array_intersect($this->studentids, $this->teacherids);
        foreach ($overlappingids as $externalid) {
            mtrace("* ПРЕДУПРЕЖДЕНИЕ: персона '{$externalid}' одновременно студент и преподаватель. Будет зачислена как преподаватель.");
        }
        $this->studentids = array_values(array_diff($this->studentids, $overlappingids));

        foreach ($this->studentids as $externalid) {
            $this->actualexternalidset[(string) $externalid] = true;
            if (!$this->enrol_person($externalid, $this->studentroleid, 'Студент', $allowduplicates)) {
                $this->missingstudentids[] = $externalid;
            }
        }
        foreach ($this->teacherids as $externalid) {
            $this->actualexternalidset[(string) $externalid] = true;
            if (!$this->enrol_person($externalid, $this->teacherroleid, 'Преподаватель', $allowduplicates)) {
                $this->missingteacherids[] = $externalid;
            }
        }

        mtrace("Отчисляем с курса {$this->courseid}");
        $this->unenrol_persons($unenrolstudents, $unenrolteachers);
    }

    /**
     * Enrols one external person when a usable Moodle user exists.
     */
    private function enrol_person($externalid, int $roleid, string $rolename, bool $allowduplicates): bool {
        $externalid = (string) $externalid;
        $internalids = $this->tointernaluserid[$externalid] ?? [];

        if (count($internalids) > 1 && !$allowduplicates) {
            $idstring = implode(',', $internalids);
            mtrace("* ОШИБКА: для персоны {$externalid} найдено несколько пользователей userid:[{$idstring}], но дублирование запрещено.");
            return false;
        }
        if (empty($internalids)) {
            mtrace("Пользователь для персоны {$externalid} (роль {$rolename}) не был найден");
            return false;
        }

        if (isset($this->existingexternalidset[$externalid])) {
            mtrace("Персона '{$externalid}' уже зачислена на курс");
            return true;
        }

        $internalid = (int) min($internalids);
        if (count($internalids) > 1) {
            $idstring = implode(',', $internalids);
            mtrace("* ПРЕДУПРЕЖДЕНИЕ: для персоны {$externalid} найдено несколько пользователей userid:[{$idstring}]. Используется userid {$internalid}.");
        }

        mtrace("Зачисляем персону '{$externalid}' как пользователя с id {$internalid} с ролью {$rolename}");
        $this->enrolplugin->enrol_user($this->enrol, $internalid, $roleid, 0, 0, null, false);
        $this->enrolledcount++;
        return true;
    }

    /**
     * Removes absent single-role manual enrolments when allowed by settings.
     */
    private function unenrol_persons(bool $unenrolstudents, bool $unenrolteachers): void {
        $coursecontext = context_course::instance($this->courseid);
        foreach (array_keys($this->existinginternalidset) as $internalid) {
            $externalid = $this->toexternaluserid[$internalid] ?? null;
            if ($externalid === null || $externalid === '' || isset($this->actualexternalidset[(string) $externalid])) {
                continue;
            }

            $roles = get_user_roles($coursecontext, $internalid, true);
            if (count($roles) !== 1) {
                mtrace("Пропускаем отчисление '{$externalid}' пользователя с id '{$internalid}': количество ролей " . count($roles));
                continue;
            }

            $role = reset($roles);
            $rolename = $role->shortname;
            $isstudent = $rolename === 'student' && $unenrolstudents;
            $isteacher = in_array($rolename, ['editingteacher', 'teacher'], true) && $unenrolteachers;
            if (!$isstudent && !$isteacher) {
                mtrace("Пропускаем отчисление '{$rolename}' '{$externalid}' как пользователя с id '{$internalid}'");
                continue;
            }

            mtrace("Отчисляем '{$rolename}' '{$externalid}' как пользователя с id '{$internalid}'");
            $this->enrolplugin->unenrol_user($this->enrol, $internalid);
            $this->unenrolledcount++;
        }
    }
}
