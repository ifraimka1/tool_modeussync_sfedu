<?php

namespace tool_modeussync\service\logger;

defined('MOODLE_INTERNAL') || die();

/** Writes SyncService diagnostics to scheduled-task output. */
final class cron_logger implements logger_interface {

    public function log(string $message): void {
        mtrace($message);
    }
}
