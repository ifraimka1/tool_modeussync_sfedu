<?php

namespace tool_modeussync\task;

use completion_info;
use Throwable;
use tool_modeussync\courses_consts;
use tool_modeussync\repository\courses_repository;
use tool_modeussync\task\base\base_sync_job;
use tool_modeussync\service\SyncService;

class pull_courses extends base_sync_job
{
    private const MAX_LOG_VALUE_LENGTH = 4096;

    private const SYNC_COURSES_BATCH_SIZE = 25;

    public function get_name()
    {
        return 'pull_courses';
    }

    public function execute()
    {
        try {
            parent::execute();
        } catch (Throwable $e) {
            $this->trace_throwable('Критическая ошибка задачи pull_courses', $e);
            throw $e;
        }
    }

    public function do_work(array $currentSession, ?array $lastClosedSession): bool
    {
        $categoryId = get_config('tool_modeussync', 'default_category');

        if (!$categoryId) {
            throw new \Exception("Error: Setting 'default_category' not set");
        }

        $prototypes = $this->lmsAdapterService->getCoursesToCreate($currentSession['id']);
        mtrace("Получено курсов из первого запроса: " . count($prototypes));

        if (count($prototypes) != 0) {
            $coursesForSync = $this->create_courses($prototypes, $categoryId);

            mtrace("Всего курсов для отправки в SyncService: " . count($coursesForSync));

            if (!empty($coursesForSync)) {
                $syncService = new SyncService();
                $batches = array_chunk($coursesForSync, self::SYNC_COURSES_BATCH_SIZE);
                $batchCount = count($batches);

                foreach ($batches as $batchIndex => $coursesBatch) {
                    $batchNumber = $batchIndex + 1;
                    mtrace("Отправляю batch {$batchNumber}/{$batchCount} во внешний сервис, курсов: " . count($coursesBatch));

                    try {
                        $syncResponse = $syncService->send_created_courses($coursesBatch);
                    } catch (Throwable $e) {
                        $this->trace_throwable("Ошибка при отправке batch {$batchNumber}/{$batchCount} в SyncService", $e);
                        throw $e;
                    }

                    mtrace("Отправлены данные о курсах во внешний сервис, batch {$batchNumber}/{$batchCount}");
                    mtrace("Ответ SyncService batch {$batchNumber}/{$batchCount}: " . $this->format_log_value($this->encode_log_value($syncResponse)));

                    $updatedCourses = $this->process_sync_response($syncResponse);
                    mtrace("Курсов для отправки на endpoint /sync, batch {$batchNumber}/{$batchCount}: " . count($updatedCourses));

                    if (!empty($updatedCourses)) {
                        try {
                            $syncResponse = $syncService->send_sync_courses($updatedCourses);
                            mtrace("Отправлены данные об обновленных курсах на endpoint /sync, batch {$batchNumber}/{$batchCount}");
                            mtrace("Ответ SyncService /sync batch {$batchNumber}/{$batchCount}: " . $this->format_log_value($this->encode_log_value($syncResponse)));
                        } catch (Throwable $e) {
                            $this->trace_throwable("Ошибка при отправке batch {$batchNumber}/{$batchCount} на endpoint /sync", $e);
                            throw $e;
                        }
                    } else {
                        mtrace("Нет курсов для отправки на endpoint /sync, batch {$batchNumber}/{$batchCount}");
                    }
                }
            }
        } else {
            mtrace("Нет курсов для обработки из первого запроса");
        }

        return true;
    }

    private function create_courses($courses, $categoryId)
    {
        global $CFG, $DB;
        require_once $CFG->dirroot . "/course/lib.php";
        require_once $CFG->libdir . '/completionlib.php';

        $getId = function ($course) {
            return $course['id'];
        };

        $idnumbers = array_map($getId, $courses);
        $existingCourses = courses_repository::get_courses_by_idnumbers($idnumbers);

        $resultcourses = array();
        foreach ($courses as $coursePrototype) {
            $fullname = $coursePrototype['name'];
            mtrace("");
            mtrace("Создаю курс [$fullname]...");
            $transaction = null;

            try {
                $idnumber = $coursePrototype['id'];
                $idModeus = $this->extract_modeus_id_from_summary($coursePrototype['summary'] ?? null);
                $existingCourse = $this->getCourseWithIdNumber($existingCourses, $idnumber);

                if ($existingCourse !== null) {
                    mtrace("Курс с IDNumber = [$idnumber] уже существует, id: ({$existingCourse->id})");

                    if ($idModeus === null) {
                        mtrace("Предупреждение: не удалось извлечь idModeus из описания курса [$fullname]");
                    } else {
                        mtrace("Извлечен idModeus [$idModeus] для курса [$fullname]");
                    }

                    $resultcourses[] = array(
                        'id_lms' => (int)$existingCourse->id,
                        'id_modeus' => $idModeus,
                    );
                    continue;
                }

                $course = $this->createCourse($coursePrototype, $categoryId);
                $transaction = $DB->start_delegated_transaction();
                $courseId = create_course((object) $course)->id;

                $this->create_sections($coursePrototype['sections'], $courseId);

                if ($idModeus === null) {
                    mtrace("Предупреждение: не удалось извлечь idModeus из описания курса [$fullname]");
                } else {
                    mtrace("Извлечен idModeus [$idModeus] для курса [$fullname]");
                }

                $resultcourses[] = array(
                    'id_lms' => $courseId,
                    'id_modeus' => $idModeus,
                );
                $transaction->allow_commit();

                mtrace("Создан курс [$fullname], id: ($courseId)");
            } catch (Throwable $e) {
                $this->trace_throwable("Ошибка при создании/обработке курса [$fullname]", $e);

                if ($transaction !== null) {
                    $transaction->rollback($e);
                }

                throw $e;
            }
        }

        return $resultcourses;
    }

    private function extract_modeus_id_from_summary(?string $summary): ?string
    {
        if (empty($summary)) {
            return null;
        }

        $pattern = '/Курс\s+создан\s+по\s+РМУП\s*\[([^\]]+)\]/u';

        if (preg_match($pattern, $summary, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function process_sync_response(array $response): array
    {
        $updatedCourses = [];

        if (empty($response)) {
            throw new \UnexpectedValueException('SyncService response is empty');
        }

        mtrace('Processing sync response...');

        if (!array_key_exists('results', $response) || !is_array($response['results'])) {
            throw new \UnexpectedValueException('SyncService response does not contain results array');
        }

        mtrace('Sync response results count: ' . count($response['results']));
        $assignmentsCount = 0;

        foreach ($response['results'] as $courseResult) {
            $courseData = $courseResult['courseData'] ?? [];

            if (is_array($courseData)) {
                $assignmentsCount += count($courseData);
            }
        }

        mtrace('Всего заданий пришло от SyncService: ' . $assignmentsCount);

        foreach ($response['results'] as $courseResult) {
            try {
                $updatedCourse = $this->create_modeus_assignments_from_result($courseResult);

                if (!empty($updatedCourse)) {
                    $updatedCourses[] = $updatedCourse;
                }
            } catch (\Throwable $e) {
                $courseResultJson = json_encode($courseResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                mtrace(
                    'Ошибка при обработке course result: ' .
                    $this->format_log_value($courseResultJson === false ? json_last_error_msg() : $courseResultJson)
                );
                mtrace('Exception class: ' . get_class($e));
                mtrace('Exception message: ' . $e->getMessage());
                mtrace('Exception file: ' . $e->getFile() . ':' . $e->getLine());
                mtrace($e->getTraceAsString());
                throw $e;
            }
        }

        return $updatedCourses;
    }

    private function create_modeus_assignments_from_result(array $courseResult): ?array
    {
        global $DB;

        $courseid = isset($courseResult['id_lms']) ? (int)$courseResult['id_lms'] : 0;
        $success = $courseResult['success'] ?? false;
        $courseData = $courseResult['courseData'] ?? [];
        $idmodeus = $courseResult['id_modeus'] ?? null;
        $courseDataCount = is_array($courseData) ? count($courseData) : 0;

        mtrace("Обрабатываю ответ SyncService для курса id_lms={$courseid}, courseDataCount={$courseDataCount}");

        if (!$success) {
            $error = $courseResult['error'] ?? $courseResult['message'] ?? 'причина не указана';
            throw new \UnexpectedValueException(
                "SyncService вернул success=false для курса id_lms={$courseid}: " .
                    $this->format_log_value($this->encode_log_value($error))
            );
        }

        if (!$courseid) {
            mtrace('Пропускаю курс: отсутствует id_lms');
            return null;
        }

        if (empty($idmodeus)) {
            mtrace("Пропускаю курс {$courseid}: отсутствует id_modeus");
            return null;
        }

        $course = $DB->get_record('course', ['id' => $courseid], 'id, idnumber', IGNORE_MISSING);
        if (!$course) {
            mtrace("Курс Moodle с id={$courseid} не найден");
            return null;
        }

        mtrace(
            "Связка идентификаторов курса: SyncService id_lms={$courseid}; " .
            "Moodle course.id={$course->id}; Moodle course.idnumber={$course->idnumber}; id_modeus={$idmodeus}"
        );

        if (empty($course->idnumber)) {
            mtrace("У курса {$courseid} пустой idnumber, пропускаю отправку на /sync");
            return null;
        }

        if (empty($courseData) || !is_array($courseData)) {
            mtrace("Для курса {$courseid} нет courseData");
            return null;
        }

        $missingCourseData = $this->filter_missing_modeus_assignment_items((int)$course->id, $courseData);

        if (empty($missingCourseData)) {
            mtrace("В курсе {$courseid} уже есть все задания, полученные от SyncService");
        } else {
            mtrace("В курсе {$courseid} будут созданы задания: " . $this->format_assignment_ids_for_log($missingCourseData));
            $sectionnum = $this->get_or_create_modeus_assignments_section((int)$course->id);

            foreach ($missingCourseData as $item) {
                try {
                    $this->create_modeus_assignment((int)$course->id, $sectionnum, $item);
                } catch (Throwable $e) {
                    $modeusitemid = trim((string)($item['id'] ?? ''));
                    $name = trim((string)($item['name'] ?? ''));
                    $this->trace_throwable(
                        "Ошибка при создании задания курса {$courseid}, Modeus id={$modeusitemid}, name={$name}",
                        $e
                    );
                    throw $e;
                }
            }
        }

        return [
            'id_modeus' => $idmodeus,
            'id_lms' => (string)$course->idnumber,
        ];
    }

    private function format_log_value(string $value): string
    {
        $length = strlen($value);

        if ($length <= self::MAX_LOG_VALUE_LENGTH) {
            return $value;
        }

        return 'length: ' . $length . ' bytes; preview: ' .
            \core_text::substr($value, 0, self::MAX_LOG_VALUE_LENGTH) .
            '... [truncated]';
    }

    private function encode_log_value($value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return 'json_encode error: ' . json_last_error_msg();
        }

        return $encoded;
    }

    private function trace_throwable(string $context, Throwable $e): void
    {
        mtrace($context);
        mtrace('Exception class: ' . get_class($e));
        mtrace('Exception message: ' . $e->getMessage());
        if ($e instanceof \moodle_exception && !empty($e->debuginfo)) {
            mtrace('Exception debuginfo: ' . $e->debuginfo);
        }
        mtrace('Exception file: ' . $e->getFile() . ':' . $e->getLine());
        mtrace('Exception trace: ' . $e->getTraceAsString());
    }

    private function filter_missing_modeus_assignment_items(int $courseid, array $courseData): array
    {
        global $DB;

        $modeusitemids = [];

        foreach ($courseData as $item) {
            $modeusitemid = trim((string)($item['id'] ?? ''));

            if ($modeusitemid !== '') {
                $modeusitemids[$modeusitemid] = true;
            }
        }

        if (empty($modeusitemids)) {
            mtrace("В курсе {$courseid} задания от SyncService без непустых id: " . count($courseData));
            return $courseData;
        }

        $sql = "SELECT cm.idnumber
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module
             WHERE cm.course = :courseid
               AND m.name = :modname
               AND cm.idnumber IS NOT NULL
               AND cm.idnumber <> ''";

        $params = [
            'courseid' => $courseid,
            'modname' => 'assign',
        ];

        $existingids = [];

        foreach ($DB->get_fieldset_sql($sql, $params) as $existingid) {
            $existingid = (string)$existingid;

            if (isset($modeusitemids[$existingid])) {
                $existingids[$existingid] = true;
            }
        }

        $missing = [];

        foreach ($courseData as $item) {
            $modeusitemid = trim((string)($item['id'] ?? ''));

            if ($modeusitemid === '' || !isset($existingids[$modeusitemid])) {
                $missing[] = $item;
            }
        }

        mtrace(
            "В курсе {$courseid} заданий от SyncService: " . count($courseData) .
            ", уже существует: " . count($existingids) .
            ", будет создано: " . count($missing)
        );
        mtrace("В курсе {$courseid} уже есть задания: " . $this->format_assignment_ids_for_log(array_keys($existingids)));
        mtrace("В курсе {$courseid} недостающие задания: " . $this->format_assignment_ids_for_log($missing));

        return $missing;
    }

    private function format_assignment_ids_for_log(array $items): string
    {
        if (empty($items)) {
            return '(нет)';
        }

        $ids = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $id = trim((string)($item['id'] ?? ''));
            } else {
                $id = trim((string)$item);
            }

            $ids[] = $id === '' ? '(пустой id)' : $id;
        }

        return $this->format_log_value(implode(', ', $ids));
    }

    private function get_or_create_modeus_assignments_section(int $courseid): int
    {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $sectionname = 'Задания из Modeus';

        $existing = $DB->get_record('course_sections', [
            'course' => $courseid,
            'name' => $sectionname,
        ], 'id, section', IGNORE_MISSING);

        if ($existing) {
            mtrace("Секция '{$sectionname}' уже существует в курсе {$courseid}, section={$existing->section}");
            return (int)$existing->section;
        }

        $lastsection = $DB->get_field_sql(
            "SELECT MAX(section)
           FROM {course_sections}
          WHERE course = ?",
            [$courseid]
        );

        $newsectionnum = ((int)$lastsection) + 1;

        course_create_section($courseid, $newsectionnum);

        $section = $DB->get_record('course_sections', [
            'course' => $courseid,
            'section' => $newsectionnum,
        ], '*', MUST_EXIST);

        $section->name = $sectionname;
        $DB->update_record('course_sections', $section);

        rebuild_course_cache($courseid, true);

        mtrace("Создана секция '{$sectionname}' в курсе {$courseid}, section={$newsectionnum}");

        return $newsectionnum;
    }

    private function create_modeus_assignment(int $courseid, int $sectionnum, array $item): void
    {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        $modeusitemid = trim((string)($item['id'] ?? ''));
        $name = trim((string)($item['name'] ?? ''));
        $grade = isset($item['grade']) ? (float)$item['grade'] : 0;

        if ($modeusitemid === '' || $name === '') {
            throw new \UnexpectedValueException(
                "Некорректное задание SyncService для курса {$courseid}: пустой id или name"
            );
        }

        $sql = "SELECT cm.id
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module
             WHERE cm.course = :courseid
               AND m.name = :modname
               AND cm.idnumber = :idnumber";

        $existing = $DB->get_record_sql($sql, [
            'courseid' => $courseid,
            'modname' => 'assign',
            'idnumber' => $modeusitemid,
        ]);

        if ($existing) {
            mtrace("Задание '{$name}' уже существует в курсе {$courseid} (Modeus id: {$modeusitemid})");
            return;
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $module = $DB->get_record('modules', ['name' => 'assign'], '*', MUST_EXIST);

        $moduleinfo = new \stdClass();
        $moduleinfo->course = $courseid;
        $moduleinfo->module = $module->id;
        $moduleinfo->modulename = 'assign';
        $moduleinfo->add = 'assign';

        $moduleinfo->name = $name;
        $moduleinfo->intro = '';
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->section = $sectionnum;

        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = $modeusitemid;
        $moduleinfo->idnumber = $modeusitemid;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->groupmembersonly = 0;
        $moduleinfo->availability = null;
        $moduleinfo->completion = 0;
        $moduleinfo->completionview = 0;
        $moduleinfo->completionexpected = 0;
        $moduleinfo->showdescription = 0;

        $moduleinfo->grade = $grade;
        $moduleinfo->gradecat = 0;

        $moduleinfo->allowsubmissionsfromdate = 0;
        $moduleinfo->duedate = 0;
        $moduleinfo->cutoffdate = 0;
        $moduleinfo->gradingduedate = 0;

        $moduleinfo->assignsubmission_onlinetext_enabled = 0;
        $moduleinfo->assignsubmission_file_enabled = 0;

        $moduleinfo->submissiondrafts = 0;
        $moduleinfo->requiresubmissionstatement = 0;
        $moduleinfo->sendnotifications = 0;
        $moduleinfo->sendlatenotifications = 0;
        $moduleinfo->sendstudentnotifications = 0;

        $moduleinfo->teamsubmission = 0;
        $moduleinfo->requireallteammemberssubmit = 0;
        $moduleinfo->blindmarking = 0;
        $moduleinfo->markingworkflow = 0;
        $moduleinfo->markingallocation = 0;

        $moduleinfo->attemptreopenmethod = 'none';
        $moduleinfo->maxattempts = -1;
        $moduleinfo->completionsubmit = 0;

        mtrace("Создаю задание '{$name}' в курсе {$courseid}, section={$sectionnum}, grade={$grade}, modeusId={$modeusitemid}");

        $created = add_moduleinfo($moduleinfo, $course);

        mtrace("Создано задание '{$name}' в курсе {$courseid}, cmid={$created->coursemodule}, grade={$grade}, modeusId={$modeusitemid}");
    }

    private function getCourseWithIdNumber($courses, $idnumber)
    {
        foreach ($courses as $k) {
            if ($k->idnumber == $idnumber) {
                return $k;
            }
        }

        return null;
    }

    private function createCourse($coursePrototype, $categoryId)
    {
        $course = array();

        if (completion_info::is_enabled_for_site()) {
            $course['enablecompletion'] = 1;
        } else {
            $course['enablecompletion'] = 0;
        }

        $course['idnumber'] = $coursePrototype['id'];
        $course['fullname'] = $coursePrototype['name'];
        $course['shortname'] = $coursePrototype['shortName'];
        $course['summary'] = $coursePrototype['summary'];
        $course['category'] = $categoryId;
        $course['lang'] = get_string_manager()->translation_exists('ru', false) ? 'ru' : 'en';
        $course['format'] = "topics";
        $course['showgrades'] = 1;
        $course['visible'] = 1;

        return $course;
    }

    private function create_sections($sections, $courseid)
    {
        foreach ($sections as $sectionProto) {
            $section = course_create_section($courseid);
            $name = $sectionProto['name'];
            course_update_section($courseid, $section, array('summary' => '', 'name' => $name));

            $this->create_modules($sectionProto['modules'], $courseid, $section->section);
        }
    }

    private function create_modules($modules, $courseid, $sectionid)
    {
        global $CFG;
        require_once $CFG->dirroot . '/mod/chat/lib.php';

        foreach ($modules as $moduleProto) {
            if (in_array($moduleProto['moduleTypeId'], courses_consts::$unsupported_module_types)) {
                mtrace("Создание элементов [" . $moduleProto['moduleTypeId'] . "] не поддерживается. Элемент [" . $moduleProto['name'] . "] не будет создан");
                continue;
            }

            try {
                $module = array();

                $module['modulename'] = $moduleProto['moduleTypeId'];
                $module['name'] = $moduleProto['name'];
                $module['course'] = $courseid;
                $module['section'] = $sectionid;
                $module['visible'] = 1;

                $module['cmidnumber'] = $moduleProto['id'];

                //заполняем поля, специфичные для того или иного элемента LMS
                $module['quizpassword'] = ''; // quiz
                $module['grade'] = 100; // workshop
                $module['gradinggrade'] = 100; // workshop
                $module['page_after_submit'] = ''; //feedback
                $module['displayformat'] = 'dictionary'; //glossary

                $module['template'] = 1; //survey

                $module['schedule'] = CHAT_SCHEDULE_NONE; //chat
                $module['chattime'] = time(); //chat

                $module['submissiondrafts'] = 0; //assigment
                $module['requiresubmissionstatement'] = 0; //assigment
                $module['sendnotifications'] = 0; //assigment
                $module['sendlatenotifications'] = 0; //assigment
                $module['duedate'] = 0; //assigment
                $module['cutoffdate'] = 0; //assigment
                $module['gradingduedate'] = 0; //assigment
                $module['allowsubmissionsfromdate'] = 0; //assigment
                $module['teamsubmission'] = 0; //assigment
                $module['requireallteammemberssubmit'] = 0; //assigment
                $module['blindmarking'] = 0; //assigment
                $module['hidegrader'] = 0; //assigment
                $module['revealidentities'] = 0; //assigment
                $module['attemptreopenmethod'] = 'none'; //assigment
                $module['maxattempts'] = -1; //assigment
                $module['markingworkflow'] = 0; //assigment
                $module['markingallocation'] = 0; //assigment
                $module['sendstudentnotifications'] = 1; //assigment
                $module['preventsubmissionnotingroup'] = 0; //assigment
                $module['activityformat'] = 0; //assigment
                $module['timelimit'] = 0; //assigment
                $module['assignsubmission_file_enabled'] = 1; //assigment
                $module['assignsubmission_file_maxfiles'] = 20; //assigment
                $module['assignsubmission_file_maxsizebytes'] = 5242880; //assigment (5 MB)

                $module['option'] = array("Добавьте варианты ответов"); //choiсe
                $module['strategy'] = 'accumulative'; //workshop

                $introtext = '';
                if ($moduleProto['moduleTypeId'] == 'label') {
                    $introtext = $moduleProto['name'];
                }
                $module['introeditor'] = array('text' => $introtext, 'format' => FORMAT_PLAIN, 'itemid' => IGNORE_FILE_MERGE);

                $module = create_module((object) $module);
            } catch (Throwable $e) {
                $type = $moduleProto['moduleTypeId'];
                $name = $moduleProto['name'];
                mtrace("Ошибка при создании модуля $type [$name]");
                throw $e;
            }
        }
    }
}
