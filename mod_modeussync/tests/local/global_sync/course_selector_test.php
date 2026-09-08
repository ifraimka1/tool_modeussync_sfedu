<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\global_sync\course_selector;
use tool_modeussync\local\queue\queue_repository;

/** Tests category scoping and exclusion of courses unrelated to mod_modeussync. */
final class course_selector_test extends advanced_testcase {

    public function test_selects_direct_category_or_all_descendants_and_excludes_unrelated_courses(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category(['name' => 'Parent']);
        $child = $generator->create_category(['name' => 'Child', 'parent' => $parent->id]);
        $grandchild = $generator->create_category(['name' => 'Grandchild', 'parent' => $child->id]);
        $sibling = $generator->create_category(['name' => 'Sibling']);

        $parentcourse = $this->create_scoped_course((int) $parent->id, 'parent-course', true, true);
        $childcourse = $this->create_scoped_course((int) $child->id, 'child-course', true, true);
        $grandchildcourse = $this->create_scoped_course(
            (int) $grandchild->id,
            'grandchild-course',
            true,
            true
        );
        $siblingcourse = $this->create_scoped_course((int) $sibling->id, 'sibling-course', true, true);
        $queueonly = $this->create_scoped_course((int) $child->id, 'queue-only', false, true);
        $moduleonly = $this->create_scoped_course((int) $child->id, 'module-only', true, false);

        $selector = new course_selector();
        $direct = $selector->get_course_ids((int) $parent->id, false);
        $recursive = $selector->get_course_ids((int) $parent->id, true);
        $expectedrecursive = [$parentcourse->id, $childcourse->id, $grandchildcourse->id];
        sort($expectedrecursive, SORT_NUMERIC);

        $this->assertSame([(int) $parentcourse->id], $direct);
        $this->assertSame(array_map('intval', $expectedrecursive), $recursive);
        $this->assertNotContains((int) $siblingcourse->id, $recursive);
        $this->assertNotContains((int) $queueonly->id, $recursive);
        $this->assertNotContains((int) $moduleonly->id, $recursive);
    }

    public function test_rejects_missing_category(): void {
        $this->resetAfterTest();
        $this->expectException(moodle_exception::class);

        (new course_selector())->get_course_ids(PHP_INT_MAX, true);
    }

    private function create_scoped_course(
        int $categoryid,
        string $idnumber,
        bool $withmodule,
        bool $withqueue
    ): stdClass {
        $course = $this->getDataGenerator()->create_course([
            'category' => $categoryid,
            'idnumber' => $idnumber,
        ]);
        if ($withmodule) {
            $this->getDataGenerator()->create_module('modeussync', ['course' => $course->id]);
        }
        if ($withqueue) {
            (new queue_repository())->upsert_course_queue($course->id, 'modeus-' . $idnumber);
        }

        return $course;
    }
}
