<?php

namespace tool_modeussync\task;

use completion_info;
use Throwable;
use tool_modeussync\courses_consts;
use tool_modeussync\repository\courses_repository;
use tool_modeussync\task\base\base_sync_job;
use tool_modeussync\service\SyncService;
use tool_modeussync\local\queue\queue_repository;
use tool_modeussync\local\queue\sync_response_ingestor;

class pull_courses extends base_sync_job
{
    private const MAX_LOG_VALUE_LENGTH = 4096;

    private const SYNC_COURSES_BATCH_SIZE = 25;

    private const COURSE_SHORTNAME_MAX_LENGTH = 255;

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

        $hadCourseCreationFailures = false;
        if (count($prototypes) != 0) {
            $creationResult = $this->create_courses($prototypes, (int) $categoryId);
            $coursesForSync = $creationResult['courses'];
            $hadCourseCreationFailures = $creationResult['failed'];

            mtrace("Всего курсов для отправки в SyncService: " . count($coursesForSync));

            if (!empty($coursesForSync)) {
                $syncService = $this->create_sync_service();
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

                    $changedqueues = $this->process_sync_response($syncResponse);
                    mtrace(
                        "Сохранено или обновлено очередей заданий, batch {$batchNumber}/{$batchCount}: " .
                        count($changedqueues)
                    );
                    mtrace('Задания ожидают настройки и подтверждения преподавателем в mod_modeussync');
                }
            }
        } else {
            mtrace("Нет курсов для обработки из первого запроса");
        }

        return !$hadCourseCreationFailures;
    }

    protected function create_courses(array $courses, int $categoryId): array
    {
        global $CFG;
        require_once $CFG->dirroot . "/course/lib.php";
        require_once $CFG->libdir . '/completionlib.php';

        $modeusIds = array();
        foreach ($courses as $coursePrototype) {
            $idModeus = $this->extract_modeus_id_from_summary($coursePrototype['summary'] ?? null);
            if ($idModeus !== null) {
                $modeusIds[$idModeus] = true;
            }
        }

        $existingCourses = array();
        if (!empty($modeusIds)) {
            $existingCourses = array_merge(
                array_values(courses_repository::get_courses_by_idnumbers(array_keys($modeusIds))),
                array_values(courses_repository::get_courses_with_modeus_references())
            );
        }
        $coursesByModeusId = $this->index_courses_by_modeus_id($existingCourses, array_keys($modeusIds));

        $resultcourses = array();
        $failed = false;
        foreach ($courses as $coursePrototype) {
            $fullname = $coursePrototype['name'];
            mtrace("");
            mtrace("Создаю курс [$fullname]...");

            try {
                $idModeus = $this->extract_modeus_id_from_summary($coursePrototype['summary'] ?? null);
                if ($idModeus === null) {
                    throw new \UnexpectedValueException(
                        "Не удалось извлечь ID РМУП из описания курса [{$fullname}]"
                    );
                }

                $existingCourseGroup = $coursesByModeusId[$idModeus] ?? null;

                if ($existingCourseGroup !== null) {
                    $existingCourse = $existingCourseGroup['selected'];
                    $this->delete_duplicate_courses(
                        $idModeus,
                        $existingCourse,
                        $existingCourseGroup['duplicates']
                    );
                    $coursesByModeusId[$idModeus]['duplicates'] = array();

                    mtrace("Курс с ID РМУП = [$idModeus] уже существует, id: ({$existingCourse->id})");

                    $this->ensure_attendance_module((int) $existingCourse->id);

                    mtrace("Извлечен idModeus [$idModeus] для курса [$fullname]");

                    $resultcourses[] = array(
                        'id_lms' => (int)$existingCourse->id,
                        'id_modeus' => $idModeus,
                    );
                    continue;
                }

                $courseId = $this->create_course_transactionally($coursePrototype, $categoryId, $idModeus);
                $coursesByModeusId[$idModeus] = [
                    'selected' => (object) [
                        'id' => $courseId,
                        'idnumber' => $idModeus,
                        'summary' => $coursePrototype['summary'],
                        'timecreated' => time(),
                    ],
                    'duplicates' => array(),
                ];

                mtrace("Извлечен idModeus [$idModeus] для курса [$fullname]");

                $resultcourses[] = array(
                    'id_lms' => $courseId,
                    'id_modeus' => $idModeus,
                );

                mtrace("Создан курс [$fullname], id: ($courseId)");
            } catch (Throwable $e) {
                $this->trace_throwable("Ошибка при создании/обработке курса [$fullname]", $e);
                $failed = true;
                continue;
            }
        }

        return ['courses' => $resultcourses, 'failed' => $failed];
    }

    /**
     * Creates one course inside its own delegated transaction.
     *
     * Moodle's rollback() rethrows the supplied exception. Keeping this boundary in a
     * helper lets create_courses() catch that rethrow and continue with the next course.
     */
    private function create_course_transactionally(array $coursePrototype, int $categoryId, string $idModeus): int
    {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $course = $this->createCourse($coursePrototype, $categoryId, $idModeus);
            $courseId = (int) create_course((object) $course)->id;
            $this->create_sections($coursePrototype['sections'], $courseId);
            $this->ensure_attendance_module($courseId);
            $transaction->allow_commit();
            return $courseId;
        } catch (Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
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

    /**
     * Индексирует существующие курсы по идентификатору РМУП и выбирает самый старый курс.
     */
    private function index_courses_by_modeus_id(array $courses, array $requestedModeusIds): array
    {
        $requestedIds = array_fill_keys($requestedModeusIds, true);
        $groupedCourses = array();

        foreach ($courses as $course) {
            $summaryModeusId = $this->extract_modeus_id_from_summary($course->summary ?? null);
            $courseModeusId = $summaryModeusId ?? trim((string) ($course->idnumber ?? ''));

            if ($courseModeusId === '' || !isset($requestedIds[$courseModeusId])) {
                continue;
            }

            $groupedCourses[$courseModeusId][(int) $course->id] = [
                'course' => $course,
                'summarymatch' => $summaryModeusId === $courseModeusId,
            ];
        }

        $coursesByModeusId = array();
        foreach ($groupedCourses as $idModeus => $courseMap) {
            $candidates = array_values($courseMap);
            usort($candidates, static function ($left, $right): int {
                $timeComparison = (int) $left['course']->timecreated <=> (int) $right['course']->timecreated;
                if ($timeComparison !== 0) {
                    return $timeComparison;
                }

                return (int) $left['course']->id <=> (int) $right['course']->id;
            });

            $summaryCandidates = array_values(array_filter(
                $candidates,
                static function (array $candidate): bool {
                    return $candidate['summarymatch'];
                }
            ));
            $selectedCandidate = $summaryCandidates[0] ?? $candidates[0];
            $duplicateCourses = array_slice($summaryCandidates, 1);

            $coursesByModeusId[$idModeus] = [
                'selected' => $selectedCandidate['course'],
                'duplicates' => array_map(static function (array $candidate): \stdClass {
                    return $candidate['course'];
                }, $duplicateCourses),
            ];
        }

        return $coursesByModeusId;
    }

    /**
     * Safely removes every non-selected Moodle course that has the same RMUP identifier.
     */
    private function delete_duplicate_courses(
        string $idModeus,
        \stdClass $selectedCourse,
        array $duplicateCourses
    ): void {
        if (empty($duplicateCourses)) {
            return;
        }

        $duplicateCourseIds = array_map(static function ($course): int {
            return (int) $course->id;
        }, $duplicateCourses);

        mtrace(
            "Предупреждение: для ID РМУП [{$idModeus}] найдено несколько курсов Moodle. " .
            "Используется самый старый курс, id: ({$selectedCourse->id}); " .
            "дубли будут удалены: [" . implode(', ', $duplicateCourseIds) . "]"
        );

        foreach ($duplicateCourses as $duplicateCourse) {
            $duplicateCourseId = (int) $duplicateCourse->id;
            mtrace("Удаляю дублирующий курс Moodle, id: ({$duplicateCourseId})...");

            if (!$this->delete_duplicate_course($duplicateCourseId)) {
                throw new \UnexpectedValueException(
                    "Не удалось удалить дублирующий курс Moodle, id: ({$duplicateCourseId})"
                );
            }

            mtrace("Дублирующий курс Moodle удалён, id: ({$duplicateCourseId})");
        }
    }

    /**
     * Uses Moodle's course deletion API so modules, contexts, grades, and plugin observers are processed.
     */
    protected function delete_duplicate_course(int $courseid): bool
    {
        return delete_course($courseid, false);
    }

    protected function create_sync_response_ingestor(): sync_response_ingestor
    {
        return new sync_response_ingestor(new queue_repository());
    }

    protected function create_sync_service(): SyncService
    {
        return new SyncService();
    }

    protected function process_sync_response(array $response): array
    {
        if (empty($response)) {
            throw new \UnexpectedValueException('SyncService response is empty');
        }

        $queues = $this->create_sync_response_ingestor()->ingest($response);
        mtrace('Очередей заданий сохранено или обновлено: ' . count($queues));

        return $queues;
    }

    private function format_log_value(string $value): string
    {
        $value = $this->redact_log_secrets($value);
        $length = strlen($value);

        if ($length <= self::MAX_LOG_VALUE_LENGTH) {
            return $value;
        }

        return 'length: ' . $length . ' bytes; preview: ' .
            mb_strcut($value, 0, self::MAX_LOG_VALUE_LENGTH, 'UTF-8') .
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
        mtrace('Exception message: ' . $this->redact_log_secrets($e->getMessage()));
        if ($e instanceof \moodle_exception && !empty($e->debuginfo)) {
            mtrace('Exception debuginfo: ' . $this->redact_log_secrets((string) $e->debuginfo));
        }
        mtrace('Exception file: ' . $e->getFile() . ':' . $e->getLine());
        mtrace('Exception trace: ' . $this->redact_log_secrets($e->getTraceAsString()));
    }

    private function redact_log_secrets(string $value): string
    {
        $apikey = trim((string) get_config('tool_modeussync', 'internal_api_key'));
        if ($apikey !== '') {
            $value = str_replace($apikey, '[redacted]', $value);
        }

        return $value;
    }

    private function createCourse($coursePrototype, $categoryId, string $idModeus)
    {
        $course = array();

        if (completion_info::is_enabled_for_site()) {
            $course['enablecompletion'] = 1;
        } else {
            $course['enablecompletion'] = 0;
        }

        $course['idnumber'] = $idModeus;
        $course['fullname'] = $coursePrototype['name'];
        $course['shortname'] = $this->get_available_shortname($coursePrototype['name']);
        $course['summary'] = $coursePrototype['summary'];
        $course['category'] = $categoryId;
        $course['lang'] = get_string_manager()->translation_exists('ru', false) ? 'ru' : 'en';
        $course['format'] = "topics";
        $course['showgrades'] = 1;
        $course['visible'] = 1;

        return $course;
    }

    private function get_available_shortname(string $fullname): string
    {
        global $DB;

        $shortname = $fullname;
        if (!$DB->record_exists('course', ['shortname' => $shortname])) {
            return $shortname;
        }

        $basename = $fullname;
        $number = 2;
        if (preg_match('/^(.*?)\s+(\d+)$/u', $fullname, $matches)) {
            $basename = $matches[1];
            $number = (int)$matches[2] + 1;
        }

        do {
            $suffix = ' ' . $number;
            $maxbaselength = self::COURSE_SHORTNAME_MAX_LENGTH - \core_text::strlen($suffix);
            $shortname = \core_text::substr($basename, 0, $maxbaselength) . $suffix;
            $number++;
        } while ($DB->record_exists('course', ['shortname' => $shortname]));

        return $shortname;
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

    /**
     * Ensures that the course has an attendance activity without adding a duplicate.
     *
     * Existing attendance activities are preserved regardless of their section. A
     * missing activity is created in section zero, which is the General section.
     */
    private function ensure_attendance_module(int $courseid): void
    {
        global $CFG, $DB;

        $attendancemodule = $DB->get_record('modules', ['name' => 'attendance']);
        if ($attendancemodule === false) {
            mtrace("Плагин mod_attendance не установлен; элемент посещаемости для курса [$courseid] не создан");
            return;
        }

        if ($DB->record_exists('course_modules', [
            'course' => $courseid,
            'module' => $attendancemodule->id,
            'deletioninprogress' => 0,
        ])) {
            return;
        }

        require_once($CFG->dirroot . '/course/modlib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $moduleinfo = (object) [
            'modulename' => 'attendance',
            'module' => $attendancemodule->id,
            'name' => get_string('modulename', 'mod_attendance'),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'section' => 0,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'cmidnumber' => '',
            'groupmode' => NOGROUPS,
            'groupingid' => 0,
        ];

        add_moduleinfo($moduleinfo, $course);
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
