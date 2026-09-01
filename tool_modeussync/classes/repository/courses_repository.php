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
}
