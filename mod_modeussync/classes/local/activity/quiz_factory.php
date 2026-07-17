<?php

namespace mod_modeussync\local\activity;

defined('MOODLE_INTERNAL') || die();

/** Creates an empty one-attempt quiz with the requested final maximum grade. */
final class quiz_factory implements activity_factory_interface {

    public function create(\stdClass $course, int $sectionnum, \stdClass $item): int {
        global $CFG, $DB;

        $this->validate_item($item);
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
        $moduleinfo = new \stdClass();
        $moduleinfo->course = $course->id;
        $moduleinfo->module = $module->id;
        $moduleinfo->modulename = 'quiz';
        $moduleinfo->add = 'quiz';
        $moduleinfo->name = $item->name;
        $moduleinfo->intro = '';
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = $item->externalid;
        $moduleinfo->idnumber = $item->externalid;
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->availability = null;
        $moduleinfo->completion = 0;
        $moduleinfo->showdescription = 0;

        $moduleinfo->grade = (float) $item->maxgrade;
        $moduleinfo->sumgrades = 0;
        $moduleinfo->attempts = 1;
        $moduleinfo->grademethod = QUIZ_GRADEHIGHEST;
        $moduleinfo->timeopen = 0;
        $moduleinfo->timeclose = 0;
        $moduleinfo->timelimit = 0;
        $moduleinfo->overduehandling = 'autosubmit';
        $moduleinfo->graceperiod = 0;
        $moduleinfo->preferredbehaviour = 'deferredfeedback';
        $moduleinfo->canredoquestions = 0;
        $moduleinfo->questionsperpage = 1;
        $moduleinfo->navmethod = 'free';
        $moduleinfo->shuffleanswers = 1;
        $moduleinfo->decimalpoints = 2;
        $moduleinfo->questiondecimalpoints = -1;
        $moduleinfo->showuserpicture = 0;
        $moduleinfo->showblocks = 0;
        $moduleinfo->password = '';
        $moduleinfo->subnet = '';
        $moduleinfo->delay1 = 0;
        $moduleinfo->delay2 = 0;
        $moduleinfo->browsersecurity = '-';
        $moduleinfo->attemptonlast = 0;
        $moduleinfo->reviewattempt = 0;
        $moduleinfo->reviewcorrectness = 0;
        $moduleinfo->reviewmarks = 0;
        $moduleinfo->reviewspecificfeedback = 0;
        $moduleinfo->reviewgeneralfeedback = 0;
        $moduleinfo->reviewrightanswer = 0;
        $moduleinfo->reviewoverallfeedback = 0;
        $moduleinfo->completionattemptsexhausted = 0;
        $moduleinfo->completionminattempts = 0;
        $moduleinfo->allowofflineattempts = 0;

        $created = add_moduleinfo($moduleinfo, $course);
        return (int) $created->coursemodule;
    }

    private function validate_item(\stdClass $item): void {
        if (empty($item->externalid) || \core_text::strlen((string) $item->externalid) > 100 ||
                empty($item->name) ||
                !isset($item->maxgrade) || !is_numeric($item->maxgrade) || (float) $item->maxgrade <= 0) {
            throw new \UnexpectedValueException('Invalid quiz queue item.');
        }
    }
}
