<?php

namespace mod_modeussync\event;

defined('MOODLE_INTERNAL') || die();

final class creation_started extends \core\event\base {
    protected function init(): void {
        $this->data['action'] = 'started';
        $this->data['target'] = 'activity_creation';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'tool_modeussync_course_queue';
    }

    public static function get_name(): string {
        return get_string('eventcreationstarted', 'mod_modeussync');
    }

    public function get_description(): string {
        return 'Activity creation started for queue ' . $this->objectid . '.';
    }
}
