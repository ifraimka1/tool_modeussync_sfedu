<?php

namespace mod_modeussync\event;

defined('MOODLE_INTERNAL') || die();

final class queue_completed extends \core\event\base {
    protected function init(): void {
        $this->data['action'] = 'completed';
        $this->data['target'] = 'assignment_queue';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'tool_modeussync_course_queue';
    }

    public static function get_name(): string {
        return get_string('eventqueuecompleted', 'mod_modeussync');
    }

    public function get_description(): string {
        return 'All activities were created for Modeus queue ' . $this->objectid . '.';
    }
}
