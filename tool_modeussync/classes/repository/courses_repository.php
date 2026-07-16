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
        $sql = "SELECT id, idnumber FROM {course} mc WHERE idnumber $insql";

        return $DB->get_records_sql($sql, $params);
    }
}