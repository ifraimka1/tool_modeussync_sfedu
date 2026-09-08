<?php

namespace mod_modeussync\task;

use mod_modeussync\local\global_sync\course_runner;
use mod_modeussync\local\global_sync\course_selector;

defined('MOODLE_INTERNAL') || die();

/** Repeats assignment synchronization for eligible courses in one category scope. */
class repeat_category_sync extends \core\task\adhoc_task {

    public function get_name(): string {
        return get_string('taskrepeatcategorysync', 'mod_modeussync');
    }

    public function execute(): void {
        $data = $this->get_custom_data();
        $keys = is_object($data) ? array_keys(get_object_vars($data)) : [];
        sort($keys);
        if ($keys !== ['categoryid', 'includesubcategories'] ||
                filter_var($data->categoryid, FILTER_VALIDATE_INT) === false ||
                (int) $data->categoryid <= 0 ||
                !is_bool($data->includesubcategories)) {
            throw new \coding_exception('Invalid global repeat-sync task data.');
        }

        $categoryid = (int) $data->categoryid;
        \core_course_category::get($categoryid, MUST_EXIST, true);
        $courseids = $this->create_selector()->get_course_ids(
            $categoryid,
            $data->includesubcategories
        );
        $result = $this->create_runner()->run($courseids);

        mtrace(get_string('taskrepeatcategorysyncsummary', 'mod_modeussync', $result));
    }

    protected function create_selector(): course_selector {
        return new course_selector();
    }

    protected function create_runner(): course_runner {
        return new course_runner();
    }
}
