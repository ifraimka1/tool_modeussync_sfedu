<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\queue\item_sorter;

/** Tests the shared presentation and creation ordering for queue items. */
final class item_sorter_test extends \advanced_testcase {

    public function test_sort_moves_only_standalone_exam_and_bonus_items_to_the_end(): void {
        $items = [
            (object) ['name' => 'Итоговый экзамен'],
            (object) ['name' => 'Яблоко'],
            (object) ['name' => 'Бонусные баллы за активность'],
            (object) ['name' => 'Экзаменационный тест'],
            (object) ['name' => 'Анализ'],
            (object) ['name' => 'Предэкзамен'],
            (object) ['name' => "Экзамен\u{0301}а"],
        ];

        $sorted = item_sorter::sort($items);

        $this->assertSame([
            'Анализ',
            "Экзамен\u{0301}а",
            'Предэкзамен',
            'Экзаменационный тест',
            'Яблоко',
            'Бонусные баллы за активность',
            'Итоговый экзамен',
        ], array_column($sorted, 'name'));
    }
}
