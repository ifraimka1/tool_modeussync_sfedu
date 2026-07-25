<?php

namespace mod_modeussync\event;

defined('MOODLE_INTERNAL') || die();

final class activity_created extends \core\event\base {
    protected function init(): void {
        $this->data['action'] = 'created';
        $this->data['target'] = 'course_module';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'course_modules';
    }

    public static function get_name(): string {
        return get_string('eventactivitycreated', 'mod_modeussync');
    }

    public function get_description(): string {
        return 'Course module ' . $this->objectid . ' was linked to a Modeus queue item.';
    }
}
