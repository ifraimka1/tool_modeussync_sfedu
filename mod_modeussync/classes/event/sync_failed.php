<?php

namespace mod_modeussync\event;

defined('MOODLE_INTERNAL') || die();

final class sync_failed extends \core\event\base {
    protected function init(): void {
        $this->data['action'] = 'failed';
        $this->data['target'] = 'sync_request';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'tool_modeussync_course_queue';
    }

    public static function get_name(): string {
        return get_string('eventsyncfailed', 'mod_modeussync');
    }

    public function get_description(): string {
        return 'SyncService request failed for Modeus queue ' . $this->objectid . '.';
    }
}
