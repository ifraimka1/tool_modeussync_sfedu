<?php

namespace mod_modeussync\event;

defined('MOODLE_INTERNAL') || die();

final class activity_creation_failed extends \core\event\base {
    protected function init(): void {
        $this->data['action'] = 'failed';
        $this->data['target'] = 'activity_creation';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'tool_modeussync_queue_items';
    }

    public static function get_name(): string {
        return get_string('eventactivitycreationfailed', 'mod_modeussync');
    }

    public function get_description(): string {
        return 'Creation failed for Modeus queue item ' . $this->objectid . '.';
    }
}
