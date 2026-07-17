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

    protected function create_sync_response_ingestor(): sync_response_ingestor
    {
        return new sync_response_ingestor(new queue_repository());
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
