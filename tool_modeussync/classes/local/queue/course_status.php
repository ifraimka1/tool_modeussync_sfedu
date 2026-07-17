<?php

namespace tool_modeussync\local\queue;

defined('MOODLE_INTERNAL') || die();

/**
 * Course queue statuses.
 */
final class course_status {

    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const AWAITING_SYNC = 'awaiting_sync';
    public const SYNCED = 'synced';
    public const SYNC_FAILED = 'sync_failed';

    private function __construct() {
    }
}
