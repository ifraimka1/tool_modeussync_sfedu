<?php

namespace tool_modeussync\task;

use tool_modeussync\repository\users_repository;
use tool_modeussync\task\base\base_sync_job;

class push_grades extends base_sync_job
{
    public function get_name()
    {
        return 'push_grades';
    }

    public function do_work(array $currentSession, ?array $lastClosedSession): bool
    {
        global $CFG;

        $currentSessionId = $currentSession['id'];
        $maximumAge = get_config('tool_modeussync', 'grades_max_interval_minutes') * 60;
        $max_age_days = $maximumAge < 86400 ? '<1' : $maximumAge / 86400;
        $safeIntervalMinutes = $currentSession['syncIntervalMinutes'] ?? 0;
        mtrace("Текущая конфигурация: grades_max_interval_minutes $maximumAge ($max_age_days days) safeIntervalMinutes $safeIntervalMinutes");
        $lastSyncEpoch = $this->epochFromSession($lastClosedSession);
        if ($lastSyncEpoch === null || time() - $lastSyncEpoch > $maximumAge) {
            $lastSyncEpoch = time() - $maximumAge;
        }
        $lastSyncEpoch = $lastSyncEpoch - ($safeIntervalMinutes * 60);

        $coursesForResync = $this->lmsAdapterService->getCoursesForGradesResync($currentSessionId);
        $grades = $this->getGradesForSync($lastSyncEpoch, $coursesForResync);
        $request = $this->filterGradesAndBuildRequest($grades);

        if (count($request->Grades) != 0) {
            $this->lmsAdapterService->pushGrades($currentSessionId, $request);
        }

        return true;
    }

    private function getGradesForSync(float $lastSyncEpoch, array $coursesForResync)
    {
        global $DB;

        mtrace("Курсы, ожидающие повторную синхронизацию: ", '');
        mtrace(join(", ", empty($coursesForResync) ? ['[]'] : $coursesForResync));
        $resync_courses_condition_sql = 'FALSE';
        $resync_courses_condition_params = [];
        $queryParams = ['last_sync_date1' => $lastSyncEpoch, 'last_sync_date2' => $lastSyncEpoch];
        if (!empty($coursesForResync)) {
            [$insql, $resync_courses_condition_params] = $DB->get_in_or_equal($coursesForResync, SQL_PARAMS_NAMED, 'coursesresync');
            $resync_courses_condition_sql = "(gi.courseid $insql)";
            $queryParams = array_merge(['last_sync_date1' => $lastSyncEpoch, 'last_sync_date2' => $lastSyncEpoch], $resync_courses_condition_params);
        }
        mtrace("Ищем измененные оценки...");
        $selectSql = "SELECT gg.id,
       cm.id cmid,
       cm.course,
       gi.id giid,
       gi.grademax,
       gi.grademin,
       gi.scaleid,
       gg.finalgrade,
       gg.userid,
       gg.usermodified,
       gg.overridden,
       gg.timecreated,
       gg.timemodified
FROM {grade_grades} gg
         INNER JOIN {grade_items} gi
                    ON gi.id = gg.itemid
         INNER JOIN {modules} M ON M.name = gi.itemmodule
         INNER JOIN {course_modules} cm
                    ON cm.course = gi.courseid AND cm.module = M.id AND cm.instance = gi.iteminstance
    -- Отбираем оценки для модулей, для которых есть хотя бы одна измененная оценка в любом grade_item: 
         LEFT JOIN (SELECT DISTINCT gi.courseid, gi.itemmodule, gi.iteminstance, gg.userid, TRUE AS matched_item_found
                     FROM {grade_grades} gg
                              INNER JOIN {grade_items} gi ON gg.itemid = gi.id
                     WHERE ((gg.overridden >= :last_sync_date1 AND gg.overridden != 0) OR
                            (gg.timemodified IS NOT NULL AND gg.timemodified >= :last_sync_date2))
                       AND gi.itemtype = 'mod'
                       -- удаленные оценки синхронизируются только по запросу:
                       AND gg.finalgrade IS NOT NULL) matched_grade_items
                    ON gi.courseid = matched_grade_items.courseid AND
                       gi.itemmodule = matched_grade_items.itemmodule AND
                       gi.iteminstance = matched_grade_items.iteminstance AND
                       gg.userid = matched_grade_items.userid 
WHERE gi.itemtype = 'mod' AND
    (matched_item_found
    -- если для курса запрошена полная пересинхронизация:
    OR $resync_courses_condition_sql)
-- Сортируем для стабильности в логах:
ORDER BY gg.id
        ";
        $grades = $DB->get_records_sql($selectSql, $queryParams);
        $gradesCount = count($grades);
        mtrace("Найдено {$gradesCount} измененных оценок.");

        return $grades;
    }

    private function filterGradesAndBuildRequest(array $grades): \stdClass
    {
        $gradeModels = [];
        $gradeModel = null;
        $users_repository = new users_repository();
        $userIdGetter = $users_repository->getUserExternalIdGetter();

        // Последовательно обрабатываем оценки, предварительно отсортированные для группировки
        mtrace("Обрабатываем исходные оценки:");
        foreach ($grades as $grade) {
            mtrace("Оценка id:{$grade->id} courseid:{$grade->course} cmid:{$grade->cmid} giid:{$grade->giid} userid:{$grade->userid}");
            $gradeExternalStudentPersonId = $userIdGetter($grade->userid);
            $gradeExternalTeacherPersonId = $grade->usermodified === null ? null : $userIdGetter($grade->usermodified);

            if (!$gradeExternalStudentPersonId) {
                mtrace("Не удалось найти сквозной идентификатор для студента {$grade->userid}. Оценка {$grade->id} будет пропущена");
                continue;
            }

            unset($gradeModel);
            $gradeModel = new \stdClass;
            $gradeModel->Id = $grade->id;
            $gradeModel->CourseId = $grade->course;
            $gradeModel->ModuleId = $grade->cmid;
            $gradeModel->ScaleId = $grade->scaleid;
            $gradeModel->GradeItemId = $grade->giid;
            $gradeModel->Value = $grade->finalgrade;
            $gradeModel->MaxValue = $grade->grademax;
            $gradeModel->MinValue = $grade->grademin;
            $gradeModel->TeacherPersonId = $gradeExternalTeacherPersonId;
            $gradeModel->StudentPersonId = $gradeExternalStudentPersonId;
            $gradeModel->OverriddenAt = $this->epochToDateString($grade->overridden);
            $gradeModel->CreatedAt = $this->epochToDateString($grade->timecreated);
            $gradeModel->ModifiedAt = $this->epochToDateString($grade->timemodified);

            $gradeModels[] = &$gradeModel;
        }
        mtrace("");

        $request = new \stdClass;
        $request->Grades = $gradeModels;

        // mtrace("Запрос в адаптер в формате json:");
        // mtrace(json_encode($request, JSON_PRETTY_PRINT));

        return $request;
    }
}
