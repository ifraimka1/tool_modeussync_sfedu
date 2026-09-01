<?php
namespace tool_modeussync\repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Репозиторий для course
 */
class courses_repository
{
    /**
     * Получает курсы по их idnumber
     *
     * @return array курсы
     */
    public static function get_courses_by_idnumbers(array $idnumbers)
    {
        if (count($idnumbers) == 0) {
            return array();
        }

        global $DB;

        list($insql, $params) = $DB->get_in_or_equal($idnumbers);
        $sql = "SELECT id, idnumber, summary, timecreated
                  FROM {course}
                 WHERE idnumber $insql";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Получает курсы, в описании которых может содержаться идентификатор РМУП.
     *
     * Точное извлечение идентификатора выполняется в pull_courses единым регулярным выражением.
     *
     * @return array курсы
     */
    public static function get_courses_with_modeus_references(): array
    {
        global $DB;

        $summarylike = $DB->sql_like('summary', ':summarypattern', false);
        $sql = "SELECT id, idnumber, summary, timecreated
                  FROM {course}
                 WHERE {$summarylike}";

        return $DB->get_records_sql($sql, ['summarypattern' => '%РМУП%']);
    }

    /**
     * Returns course ids that are targets of Moodle's course-copy restore operation.
     *
     * Completed controllers are included deliberately. The course_restored observer removes
     * the inherited Modeus reference, while this lookup closes the race before that observer runs.
     *
     * @param array $courseids Candidate Moodle course ids.
     * @return array Set keyed by protected course id.
     */
    public static function get_course_copy_restore_targets(array $courseids): array
    {
        global $CFG, $DB;

        if (empty($courseids)) {
            return array();
        }

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        list($insql, $params) = $DB->get_in_or_equal(
            array_map('intval', $courseids),
            SQL_PARAMS_NAMED,
            'copycourse'
        );
        $params['operation'] = \backup::OPERATION_RESTORE;
        $params['type'] = \backup::TYPE_1COURSE;
        $params['purpose'] = \backup::MODE_COPY;
        $sql = "SELECT DISTINCT itemid
                  FROM {backup_controllers}
                 WHERE itemid {$insql}
                   AND operation = :operation
                   AND type = :type
                   AND purpose = :purpose";

        return array_fill_keys($DB->get_fieldset_sql($sql, $params), true);
    }
}
