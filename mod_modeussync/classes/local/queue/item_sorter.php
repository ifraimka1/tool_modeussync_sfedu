<?php

namespace mod_modeussync\local\queue;

defined('MOODLE_INTERNAL') || die();

/** Provides the shared display and creation order for Modeus queue items. */
final class item_sorter {

    /**
     * Sorts regular items alphabetically, placing exam and bonus items last.
     *
     * @param array $items Queue item records.
     * @return array Sorted queue item records.
     */
    public static function sort(array $items): array {
        $decorated = [];
        foreach (array_values($items) as $position => $item) {
            $name = trim((string) ($item->name ?? ''));
            $decorated[] = [
                'item' => $item,
                'position' => $position,
                'special' => self::is_exam_or_bonus($name),
                'name' => \core_text::strtolower($name),
            ];
        }

        usort($decorated, static function(array $left, array $right): int {
            $specialcomparison = $left['special'] <=> $right['special'];
            if ($specialcomparison !== 0) {
                return $specialcomparison;
            }

            $namecomparison = strnatcmp($left['name'], $right['name']);
            return $namecomparison !== 0 ? $namecomparison : $left['position'] <=> $right['position'];
        });

        return array_column($decorated, 'item');
    }

    /** Determines whether a name contains an exam or bonus marker as a whole word. */
    private static function is_exam_or_bonus(string $name): bool {
        return preg_match(
            '/(?<![\\p{L}\\p{M}\\p{N}_])(?:экзамен(?![\\p{L}\\p{M}\\p{N}_])|бонус[\\p{L}\\p{M}]*)/iu',
            $name
        ) === 1;
    }
}
