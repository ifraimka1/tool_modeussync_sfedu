<?php

namespace mod_modeussync\task;

use mod_modeussync\local\instance_manager;
use tool_modeussync\local\queue\queue_repository;

defined('MOODLE_INTERNAL') || die();

/** Restores missing system activities for persistent queues. */
final class ensure_instances extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('ensureinstances', 'mod_modeussync');
    }

    public function execute(): void {
        global $DB;

        $repository = new queue_repository();
        $manager = new instance_manager($repository);

        foreach ($repository->get_course_ids_with_items(100) as $courseid) {
            try {
                if (!$DB->record_exists('course', ['id' => $courseid])) {
                    $repository->delete_course_queue((int) $courseid);
                    continue;
                }
                $manager->ensure_for_course((int) $courseid);
            } catch (\Throwable $exception) {
                mtrace(
                    'mod_modeussync: cannot ensure activity for course ' . (int) $courseid .
                    ': ' . get_class($exception)
                );
            }
        }
    }
}
