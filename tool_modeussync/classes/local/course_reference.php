<?php

namespace tool_modeussync\local;

defined('MOODLE_INTERNAL') || die();

/** Parses and removes the Modeus course reference stored in a Moodle course summary. */
final class course_reference {

    private const MODEUS_ID_PATTERN = '/Курс\s+создан\s+по\s+РМУП\s*\[([^\]]+)\]/u';

    public static function extract_modeus_id(?string $summary): ?string {
        if (empty($summary)) {
            return null;
        }

        if (preg_match(self::MODEUS_ID_PATTERN, $summary, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public static function remove_modeus_reference(string $summary): string {
        $updated = preg_replace(self::MODEUS_ID_PATTERN, '', $summary);
        if ($updated === null) {
            return $summary;
        }

        return trim($updated);
    }
}
