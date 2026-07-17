<?php

namespace tool_modeussync\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event emitted after assignments have been persisted in a course queue.
 */
final class assignments_queued extends \core\event\base {

    /**
     * Initialises event metadata.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_modeussync_course_queue';
    }

    /**
     * Gets the localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventassignmentsqueued', 'tool_modeussync');
    }

    /**
     * Gets an event description for logs.
     *
     * @return string
     */
    public function get_description(): string {
        return 'Queue ' . $this->objectid . ' received ' . $this->other['itemcount'] . ' assignment items.';
    }
}
