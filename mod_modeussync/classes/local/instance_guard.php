<?php

namespace mod_modeussync\local;

defined('MOODLE_INTERNAL') || die();

/** Request-local authorization for system-managed instance lifecycle operations. */
final class instance_guard {
    /** @var int */
    private static $adddepth = 0;

    /** @var int */
    private static $deletedepth = 0;

    private function __construct() {
    }

    /**
     * @param callable $callback System add operation.
     * @return mixed
     */
    public static function run_system_add(callable $callback) {
        self::$adddepth++;
        try {
            return $callback();
        } finally {
            self::$adddepth--;
        }
    }

    public static function is_system_add_allowed(): bool {
        return self::$adddepth > 0;
    }

    /**
     * @param callable $callback System delete operation.
     * @return mixed
     */
    public static function run_system_delete(callable $callback) {
        self::$deletedepth++;
        try {
            return $callback();
        } finally {
            self::$deletedepth--;
        }
    }

    public static function is_system_delete_allowed(): bool {
        return self::$deletedepth > 0;
    }
}
