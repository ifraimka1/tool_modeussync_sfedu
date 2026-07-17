<?php

namespace tool_modeussync\local\queue;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle activity modules supported by the manual queue.
 */
final class target_module {

    public const ASSIGN = 'assign';
    public const QUIZ = 'quiz';
    public const DEFAULT = self::ASSIGN;

    /**
     * Returns whether the target module is supported by the queue.
     *
     * @param string $value Target module name.
     * @return bool
     */
    public static function is_supported(string $value): bool {
        return in_array($value, [self::ASSIGN, self::QUIZ], true);
    }

    private function __construct() {
    }
}
