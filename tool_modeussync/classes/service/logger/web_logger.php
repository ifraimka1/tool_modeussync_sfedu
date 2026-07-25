<?php

namespace tool_modeussync\service\logger;

defined('MOODLE_INTERNAL') || die();

/** Writes SyncService diagnostics to the server log without corrupting HTML responses. */
final class web_logger implements logger_interface {

    public function log(string $message): void {
        error_log('[tool_modeussync SyncService] ' . $message);
    }
}
