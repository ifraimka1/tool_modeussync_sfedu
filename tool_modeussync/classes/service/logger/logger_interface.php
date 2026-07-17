<?php

namespace tool_modeussync\service\logger;

defined('MOODLE_INTERNAL') || die();

/** Receives server-side SyncService diagnostic messages. */
interface logger_interface {

    /**
     * @param string $message Safe diagnostic message without credentials.
     * @return void
     */
    public function log(string $message): void;
}
