<?php

defined('MOODLE_INTERNAL') || die();

/** Test seam exposing grade selection and serialization. */
final class testable_push_grades extends \tool_modeussync\task\push_grades {
    public function select_grades(float $lastsyncepoch, array $coursesforresync): array {
        return $this->getGradesForSync($lastsyncepoch, $coursesforresync);
    }

    public function build_request(array $grades): \stdClass {
        return $this->filterGradesAndBuildRequest($grades);
    }
}

/** Tests grade selection and adapter payload construction. */
final class push_grades_test extends advanced_testcase {

    public function test_module_and_non_module_items_receive_stable_module_ids(): void {
        $this->resetAfterTest();
        set_config('user_sync_field', 'user::idnumber', 'tool_modeussync');
        $student = $this->getDataGenerator()->create_user(['idnumber' => 'student-person']);
        $teacher = $this->getDataGenerator()->create_user(['idnumber' => 'teacher-person']);
        $base = [
            'course' => 77,
            'grademax' => 100.0,
            'grademin' => 0.0,
            'scaleid' => null,
            'finalgrade' => 80.0,
            'userid' => (int) $student->id,
            'usermodified' => (int) $teacher->id,
            'overridden' => 0,
            'timecreated' => 1000,
            'timemodified' => 2000,
        ];
        $grades = [
            (object) ($base + ['id' => 1, 'cmid' => 321, 'giid' => 101, 'itemtype' => 'mod']),
            (object) ($base + ['id' => 2, 'cmid' => null, 'giid' => 102, 'itemtype' => 'course']),
            (object) ($base + ['id' => 3, 'cmid' => null, 'giid' => 103, 'itemtype' => 'category']),
            (object) ($base + ['id' => 4, 'cmid' => null, 'giid' => 104, 'itemtype' => 'manual']),
        ];

        $request = (new testable_push_grades())->build_request($grades);

        $this->assertCount(4, $request->Grades);
        $this->assertSame(321, $request->Grades[0]->ModuleId);
        $this->assertSame('grade_item_102', $request->Grades[1]->ModuleId);
        $this->assertSame('grade_item_103', $request->Grades[2]->ModuleId);
        $this->assertSame('grade_item_104', $request->Grades[3]->ModuleId);
        $this->assertSame('student-person', $request->Grades[0]->StudentPersonId);
        $this->assertSame('teacher-person', $request->Grades[0]->TeacherPersonId);
    }

    public function test_selects_mod_course_category_and_manual_grade_items(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');
        require_once($CFG->libdir . '/grade/grade_category.php');

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentroleid);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'grade' => 100,
        ]);

        $moditem = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
        ]);
        $courseitem = \grade_item::fetch_course_item($course->id);
        $category = new \grade_category((object) [
            'courseid' => $course->id,
            'fullname' => 'Modeus category',
        ], false);
        $category->insert();
        $categoryitem = $category->load_grade_item();
        $manualitem = new \grade_item((object) [
            'courseid' => $course->id,
            'itemtype' => 'manual',
            'itemname' => 'Modeus manual item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademin' => 0,
            'grademax' => 100,
        ], false);
        $manualitem->insert();

        $now = time();
        foreach ([$moditem, $courseitem, $categoryitem, $manualitem] as $index => $item) {
            $grade = new \grade_grade((object) [
                'itemid' => $item->id,
                'userid' => $student->id,
                'rawgrade' => 70 + $index,
                'finalgrade' => 70 + $index,
                'overridden' => $now,
                'timecreated' => $now,
                'timemodified' => $now,
            ], false);
            $grade->insert();
        }

        $grades = (new testable_push_grades())->select_grades($now - 60, []);
        $selecteditemids = array_map(static fn($grade): int => (int) $grade->giid, $grades);
        sort($selecteditemids, SORT_NUMERIC);
        $expecteditemids = array_map(
            static fn($item): int => (int) $item->id,
            [$moditem, $courseitem, $categoryitem, $manualitem]
        );
        sort($expecteditemids, SORT_NUMERIC);

        $this->assertSame($expecteditemids, $selecteditemids);

        $resyncgrades = (new testable_push_grades())->select_grades($now + 3600, [$course->id]);
        $resyncitemids = array_map(static fn($grade): int => (int) $grade->giid, $resyncgrades);
        sort($resyncitemids, SORT_NUMERIC);
        $this->assertSame($expecteditemids, $resyncitemids);
    }
}
