<?php

defined('MOODLE_INTERNAL') || die();

/** Module response double used by global synchronization SyncService fakes. */
final class modeussync_global_test_adapter_service {
    public function __construct() {
    }

    public function getModeusCourseModules(string $idmodeus): array {
        global $DB;
        $queue = $DB->get_record('tool_modeussync_course_queue', ['idmodeus' => $idmodeus], '*', MUST_EXIST);
        return array_map(static function($item): array {
            return json_decode($item->payloadjson, true);
        }, (new \tool_modeussync\local\queue\queue_repository())->get_items($queue->id));
    }

    public function getCourseModules(string $courseid): array {
        global $DB;
        $map = $DB->get_record('tool_modeussync_course_map', ['prototypeid' => $courseid], '*', MUST_EXIST);
        $modules = [];
        foreach ($DB->get_records('course_modules', ['course' => $map->courseid]) as $cm) {
            if ($cm->idnumber === null || $cm->idnumber === '') {
                continue;
            }
            $hash = md5($cm->idnumber);
            $modules[] = [
                'id' => substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' .
                    substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12),
                'lmsIdNumber' => $cm->idnumber,
            ];
        }
        return $modules;
    }
}
