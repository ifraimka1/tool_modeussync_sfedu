<?php

namespace tool_modeussync\task;

use tool_modeussync\courses_consts;
use tool_modeussync\task\base\base_sync_job;

class push_courses extends base_sync_job
{
    public const name = 'push_courses';

    public function get_name()
    {
        return push_courses::name;
    }

    public function do_work(array $currentSession, ?array $lastClosedSession): bool
    {
        $safeIntervalMinutes = $currentSession['syncIntervalMinutes'] ?? 0;
        $courses_max_interval_minutes = get_config('tool_modeussync', 'courses_max_interval_minutes') * 60;
        $courses_max_interval_minutes_days = $courses_max_interval_minutes < 86400 ? '<1' : $courses_max_interval_minutes / 86400;
        mtrace("Текущая конфигурация: courses_max_interval_minutes $courses_max_interval_minutes ($courses_max_interval_minutes_days days), safeIntervalMinutes $safeIntervalMinutes");
        $minimumUpdatedAt = time() - $courses_max_interval_minutes;
        $lastSyncTime = $this->epochFromSession($lastClosedSession);
        if ($lastSyncTime === null || time() - $lastSyncTime > $courses_max_interval_minutes) {
            $lastSyncTime = time() - $courses_max_interval_minutes;
        }
        $lastSyncTime = $lastSyncTime - ($safeIntervalMinutes * 60);

        $courses = $this->getCoursesToPush($minimumUpdatedAt, $lastSyncTime);
        $moduleTypeInfos = $this->get_module_types();

        $request = new \stdClass;
        $request->courses = $courses;
        $request->moduleTypes = $moduleTypeInfos;
        $request->deletedCourseIds = $this->getDeletedCourseIds($minimumUpdatedAt, $lastSyncTime);

        $this->lmsAdapterService->updateCoursesAndModuleTypes($currentSession['id'], $request);

        return true;
    }

    private function getCoursesToPush($minimumUpdatedAt, ?int $lastSyncTime): array
    {
        global $CFG, $DB;

        mtrace("Ищем курсы для отправки...");
        $fromSql = "
        FROM {course} c
        LEFT JOIN (
            SELECT DISTINCT(l.courseid) as courseid
            FROM {logstore_standard_log} l
            WHERE 
                objecttable = 'course_modules'
                AND (action = 'created' OR action = 'updated' OR action = 'deleted')
                AND (:last_sync_date_is_null_1 OR :last_sync_date2 <= l.timecreated)
        ) l ON l.courseid = c.id
        LEFT JOIN (
            SELECT DISTINCT(gi.courseid) as courseid
            FROM {grade_items} gi
            LEFT JOIN {logstore_standard_log} lg ON gi.id = lg.objectid
            WHERE
                lg.objecttable = 'grade_items' 
                AND (gi.itemtype = 'course' OR gi.itemtype = 'category' OR gi.itemtype = 'manual')
                AND (lg.action = 'created' OR lg.action = 'updated' OR lg.action = 'deleted')
                AND (:last_sync_date_is_null_6 OR :last_sync_date7 <= lg.timecreated)
        ) g ON g.courseid = c.id
        WHERE c.timemodified >= :minimum_date
        AND ((:last_sync_date_is_null_3 OR :last_sync_date4 <= c.timecreated OR :last_sync_date5 <= c.timemodified) 
                OR l.courseid is not null 
                OR g.courseid is not null)";

        $countSql = "SELECT count(DISTINCT c.id) {$fromSql}";
        $queryParams = [
            'minimum_date' => $minimumUpdatedAt,
            'last_sync_date_is_null_1' => $lastSyncTime == null,
            'last_sync_date2' => $lastSyncTime,
            'last_sync_date_is_null_3' => $lastSyncTime == null,
            'last_sync_date4' => $lastSyncTime,
            'last_sync_date5' => $lastSyncTime,
            'last_sync_date_is_null_6' => $lastSyncTime == null,
            'last_sync_date7' => $lastSyncTime,
        ];

        $coursesCount = $DB->count_records_sql($countSql, $queryParams);
        $lastSyncTimeStr = $lastSyncTime === null ? '--' : $this->epochToDisplayDateString($lastSyncTime);
        mtrace("Найдено {$coursesCount} релевантных курсов с изменениями >= $lastSyncTime ({$lastSyncTimeStr})");

        mtrace("Загружаем данные из БД...");
        $selectSql = "SELECT c.* {$fromSql}";
        $courses = $DB->get_records_sql($selectSql, $queryParams);

        $courseModels = [];
        foreach ($courses as $course) {
            $nextCourse = [];
            $nextCourse['id'] = $course->id;
            $nextCourse['lmsIdNumber'] = $course->idnumber;
            $nextCourse['name'] = $course->fullname;
            $nextCourse['modules'] = $this->getCourseModules($course);

            $courseModels[] = $nextCourse;
        }
        return $courseModels;
    }

    public function getCourseModules($course)
    {
        global $CFG, $DB;
        require_once $CFG->dirroot . "/course/lib.php";

        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();
        $modinfosections = $modinfo->get_sections();
        $modules = array();

        mtrace("Загружаем из БД модули курса [$course->fullname] (id: $course->id)");

        foreach ($sections as $key => $section) {
            if (!array_key_exists($section->section, $modinfosections)) {
                continue;
            }

            foreach ($modinfosections[$section->section] as $courseModuleId) {
                $courseModuleInfo = $modinfo->cms[$courseModuleId];
                $modules[] = [
                    'id' => $courseModuleInfo->id,
                    'lmsIdNumber' => $courseModuleInfo->idnumber,
                    'name' => $courseModuleInfo->name,
                    'moduleTypeId' => $courseModuleInfo->modname
                ];
            }
        }

        mtrace("Загружаем модули оценок курса [$course->fullname] (id: $course->id)");
        $gradeitems = $this->getCourseGradeItems($course);
        mtrace("Найдено элементов оценок: " . count($gradeitems));

        foreach ($gradeitems as $gradeitem) {
            $modules[] = [
                'id' => "grade_item_{$gradeitem['id']}",
                'lmsIdNumber' => null,
                'name' => $gradeitem['idnumber'],
                'moduleTypeId' => 'calculated_score'
            ];
        }

        return $modules;
    }

    private function getCourseGradeItems($course): array
    {
        global $DB;

        // Получаем только вычисляемые grade items (course и category)
        $gradeItems = $DB->get_records_sql("
            SELECT DISTINCT gi.* 
            FROM {grade_items} gi
            LEFT JOIN {logstore_standard_log} l ON l.objectid = gi.id AND l.objecttable = 'grade_items'
            WHERE gi.courseid = :courseid
                AND (gi.itemtype = 'course' OR gi.itemtype = 'category' OR gi.itemtype = 'manual')
                AND gi.calculation IS NOT NULL
                AND gi.idnumber IS NOT NULL AND gi.idnumber <> ''
                AND (l.id IS NULL OR l.action IN ('created', 'updated', 'deleted'))", ['courseid' => $course->id]);

        $result = [];

        foreach ($gradeItems as $item) {
            $result[] = [
                'id' => $item->id,
                'itemname' => $item->itemname,
                'itemtype' => $item->itemtype,
                'calculation' => $item->calculation,
                'idnumber' => $item->idnumber,
                'grademax' => $item->grademax,
                'grademin' => $item->grademin,
                'gradepass' => $item->gradepass,
                'aggregationcoef' => $item->aggregationcoef,
                'aggregationcoef2' => $item->aggregationcoef2,
                'weightoverride' => $item->weightoverride,
                'scaleid' => $item->scaleid,
            ];
        }

        return $result;
    }

    public function get_module_types()
    {
        $oldLanguage = force_current_language('ru');
        try {
            global $CFG, $DB;
            require_once $CFG->dirroot . "/course/lib.php";

            $types = $DB->get_records('modules', array('visible' => true));

            $moduleTypeInfos = array();
            foreach ($types as $moduleType) {
                $moduleTypeinfo = array();
                $moduleTypeinfo['id'] = $moduleType->name;
                $moduleTypeinfo['name'] = $this->get_module_label($moduleType->name);
                $moduleTypeinfo['canCreate'] = !in_array($moduleType->name, courses_consts::$unsupported_module_types);

                $moduleTypeInfos[] = $moduleTypeinfo;
            }

            $moduleTypeInfos[] = [
                'id' => 'calculated_score',
                'name' => 'Вычисляемая оценка',
                'canCreate' => false
            ];

            return $moduleTypeInfos;
        } finally {
            force_current_language($oldLanguage);
        }
    }

    private static function get_module_label(string $modulename): string
    {
        if (get_string_manager()->string_exists('modulename', $modulename)) {
            $modulename = get_string('modulename', $modulename);
        }

        return $modulename;
    }

    private function getDeletedCourseIds($minimumUpdatedAt, ?int $lastSyncTime): array
    {
        global $CFG, $DB;

        mtrace("Ищем логи об удаленных курсах...");
        $fromSql = "FROM {logstore_standard_log} l
                WHERE objecttable = 'course' AND action = 'deleted' AND :minimum_date <= l.timecreated AND (:last_sync_date_is_null_1 OR :last_sync_date2 <= l.timecreated)";
        $countSql = "SELECT count(DISTINCT l.courseid) {$fromSql}";
        $queryParams = [
            'minimum_date' => $minimumUpdatedAt,
            'last_sync_date_is_null_1' => $lastSyncTime == null,
            'last_sync_date2' => $lastSyncTime,
        ];
        $coursesCount = $DB->count_records_sql($countSql, $queryParams);
        $lastSyncTimeStr = $lastSyncTime === null ? '--' : $lastSyncTime;
        mtrace("Найдено {$coursesCount} удаленных курсов (Начиная с временной точки: {$lastSyncTimeStr})");

        mtrace("Загружаем идентификаторы удаленных курсов из БД...");
        $selectSql = "SELECT DISTINCT l.courseid as id {$fromSql}";
        $courses = $DB->get_records_sql($selectSql, $queryParams);

        return array_map(fn($c) => $c->id, array_values($courses));
    }
}
