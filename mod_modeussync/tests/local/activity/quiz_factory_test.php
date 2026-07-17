<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\activity\quiz_factory;
use mod_modeussync\local\activity\section_manager;

/** Tests creation of the intentionally empty first-release Modeus quiz. */
final class quiz_factory_test extends advanced_testcase {

    public function test_factory_creates_empty_quiz_with_requested_grade_policy(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $sectionnum = (new section_manager())->get_or_create($course->id);
        $item = (object) [
            'externalid' => 'meeting-quiz-1',
            'name' => 'Итоговый тест',
            'maxgrade' => 62,
        ];

        $cmid = (new quiz_factory())->create($course, $sectionnum, $item);
        $cm = get_coursemodule_from_id('quiz', $cmid, $course->id, false, MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $gradeitem = $DB->get_record('grade_items', [
            'courseid' => $course->id,
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
        ], '*', MUST_EXIST);

        $this->assertSame('meeting-quiz-1', $cm->idnumber);
        $this->assertSame('Итоговый тест', $quiz->name);
        $this->assertEquals(62.0, (float) $quiz->grade);
        $this->assertEquals(62.0, (float) $gradeitem->grademax);
        $this->assertEquals(0.0, (float) $quiz->sumgrades);
        $this->assertSame(1, (int) $quiz->attempts);
        $this->assertSame(QUIZ_GRADEHIGHEST, (int) $quiz->grademethod);
        foreach ([
            'reviewattempt',
            'reviewcorrectness',
            'reviewmarks',
            'reviewspecificfeedback',
            'reviewgeneralfeedback',
            'reviewrightanswer',
            'reviewoverallfeedback',
        ] as $reviewfield) {
            $this->assertSame(0, (int) $quiz->{$reviewfield}, $reviewfield . ' must be disabled');
        }
        $this->assertSame(0, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
    }
}
