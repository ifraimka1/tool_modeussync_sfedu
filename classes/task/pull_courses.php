<?php

namespace tool_modeussync\task;

use completion_info;
use Throwable;
use tool_modeussync\courses_consts;
use tool_modeussync\debug_utils;
use tool_modeussync\repository\courses_repository;
use tool_modeussync\task\base\base_sync_job;
use tool_modeussync\service\SyncService;

class pull_courses extends base_sync_job
{
    public function get_name()
    {
        return 'pull_courses';
    }

    public function do_work(array $currentSession, ?array $lastClosedSession): bool
    {
        $categoryId = get_config('tool_modeussync', 'default_category');

        if (!$categoryId) {
            throw new \Exception("Error: Setting 'default_category' not set");
        }

        $prototypes = $this->lmsAdapterService->getCoursesToCreate($currentSession['id']);

        if (count($prototypes) != 0) {
            $created = $this->create_courses($prototypes, $categoryId);

            mtrace("Всего создано " . count($created) . " курсов");

            if (!empty($created)) {
                $syncService = new SyncService();
                $syncResponse = $syncService->send_created_courses($created);

                mtrace('Отправлены данные о созданных курсах во внешний сервис');

                $updatedCourses = $this->process_sync_response($syncResponse);

                if (!empty($updatedCourses)) {
                    $syncService->send_sync_courses($updatedCourses);
                    mtrace('Отправлены данные об обновленных курсах на endpoint /sync');
                } else {
                    mtrace('Нет курсов для отправки на endpoint /sync');
                }
            }
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

            try {
                $idnumber = $coursePrototype['id'];
                if ($this->hasCourseWithIdNumber($existingCourses, $idnumber)) {
                    mtrace("Курс с IDNumber = [$idnumber] уже существует");
                    continue;
                }

                $course = $this->createCourse($coursePrototype, $categoryId);
                $transaction = $DB->start_delegated_transaction();
                $courseId = create_course((object) $course)->id;

                $this->create_sections($coursePrototype['sections'], $courseId);

                $idModeus = $this->extract_modeus_id_from_summary($course['summary']);

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
                mtrace("Ошибка при создании курса [$fullname]:");
                debug_utils::traceError($e);
                $DB->force_transaction_rollback();
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
            mtrace('Sync response is empty');
            return $updatedCourses;
        }

        mtrace('Processing sync response...');

        if (empty($response['results']) || !is_array($response['results'])) {
            mtrace('Sync response does not contain results array');
            return $updatedCourses;
        }

        foreach ($response['results'] as $courseResult) {
            try {
                $updatedCourse = $this->create_modeus_assignments_from_result($courseResult);

                if (!empty($updatedCourse)) {
                    $updatedCourses[] = $updatedCourse;
                }
            } catch (\Throwable $e) {
                mtrace('Ошибка при обработке course result: ' . json_encode($courseResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                mtrace('Exception class: ' . get_class($e));
                mtrace('Exception message: ' . $e->getMessage());
                mtrace('Exception file: ' . $e->getFile() . ':' . $e->getLine());
                mtrace($e->getTraceAsString());
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

        if (!$success) {
            mtrace("Пропускаю курс: success=false, id_lms={$courseid}");
            return null;
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

        if (empty($course->idnumber)) {
            mtrace("У курса {$courseid} пустой idnumber, пропускаю отправку на /sync");
            return null;
        }

        if (empty($courseData) || !is_array($courseData)) {
            mtrace("Для курса {$courseid} нет courseData");
            return null;
        }

        $sectionnum = $this->get_or_create_modeus_assignments_section((int)$course->id);

        foreach ($courseData as $item) {
            $this->create_modeus_assignment((int)$course->id, $sectionnum, $item);
        }

        return [
            'id_modeus' => $idmodeus,
            'id_lms' => (string)$course->idnumber,
        ];
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
            mtrace('Пропускаю элемент курса: пустой id или name');
            return;
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
        $moduleinfo->cmidnumber = '';
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

    private function hasCourseWithIdNumber($courses, $idnumber)
    {
        foreach ($courses as $k) {
            if ($k->idnumber == $idnumber) {
                return true;
            }
        }

        return false;
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
