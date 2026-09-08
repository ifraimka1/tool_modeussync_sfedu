<?php

namespace mod_modeussync\local\global_sync;

defined('MOODLE_INTERNAL') || die();

/** Selects courses eligible for global repeat synchronization. */
final class course_selector {

    /**
     * Returns courses from the selected category scope that own a real mod_modeussync and a queue.
     *
     * @param int $categoryid Selected course category id.
     * @param bool $includesubcategories Whether descendants are included recursively.
     * @return int[] Course ids ordered ascending.
     */
    public function get_course_ids(int $categoryid, bool $includesubcategories): array {
        global $DB;

        if ($categoryid <= 0) {
            throw new \invalid_parameter_exception('Course category id must be a positive integer.');
        }
        $category = \core_course_category::get($categoryid, MUST_EXIST, true);
        $categoryids = [$categoryid];
        if ($includesubcategories) {
            $categoryids = array_merge($categoryids, $category->get_all_children_ids());
        }
        $categoryids = array_values(array_unique(array_map('intval', $categoryids)));
        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'category');
        $params['modulename'] = 'modeussync';

        $sql = "SELECT DISTINCT c.id
                  FROM {course} c
                  JOIN {modeussync} ms ON ms.course = c.id
                  JOIN {modules} m ON m.name = :modulename
                  JOIN {course_modules} cm
                    ON cm.course = c.id
                   AND cm.module = m.id
                   AND cm.instance = ms.id
                   AND cm.deletioninprogress = 0
                  JOIN {tool_modeussync_course_queue} cq ON cq.courseid = c.id
                 WHERE c.category {$insql}
              ORDER BY c.id ASC";
        $records = $DB->get_records_sql($sql, $params);

        return array_map('intval', array_keys($records));
    }
}
