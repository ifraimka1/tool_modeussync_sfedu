<?php

namespace tool_modeussync\local\queue;

defined('MOODLE_INTERNAL') || die();

/**
 * Queue item statuses.
 */
final class item_status {

    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const CREATED = 'created';
    public const FAILED = 'failed';

    private function __construct() {
    }
}
