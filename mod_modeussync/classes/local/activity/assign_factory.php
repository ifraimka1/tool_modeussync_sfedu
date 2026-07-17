<?php

namespace mod_modeussync\local\activity;

defined('MOODLE_INTERNAL') || die();

/** Creates a grade-bearing assignment while preserving the previous automatic defaults. */
final class assign_factory implements activity_factory_interface {

    public function create(\stdClass $course, int $sectionnum, \stdClass $item): int {
        global $CFG, $DB;

        $this->validate_item($item);
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        $module = $DB->get_record('modules', ['name' => 'assign'], '*', MUST_EXIST);
        $moduleinfo = new \stdClass();
        $moduleinfo->course = $course->id;
        $moduleinfo->module = $module->id;
        $moduleinfo->modulename = 'assign';
        $moduleinfo->add = 'assign';
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
        $moduleinfo->gradecat = 0;
        $moduleinfo->allowsubmissionsfromdate = 0;
        $moduleinfo->duedate = 0;
        $moduleinfo->cutoffdate = 0;
        $moduleinfo->gradingduedate = 0;
        $moduleinfo->assignsubmission_onlinetext_enabled = 0;
        $moduleinfo->assignsubmission_file_enabled = 0;
        $moduleinfo->submissiondrafts = 0;
        $moduleinfo->requiresubmissionstatement = 0;
        $moduleinfo->sendnotifications = 0;
        $moduleinfo->sendlatenotifications = 0;
        $moduleinfo->sendstudentnotifications = 0;
        $moduleinfo->teamsubmission = 0;
        $moduleinfo->requireallteammemberssubmit = 0;
        $moduleinfo->blindmarking = 0;
        $moduleinfo->markingworkflow = 0;
        $moduleinfo->markingallocation = 0;
        $moduleinfo->attemptreopenmethod = 'none';
        $moduleinfo->maxattempts = -1;
        $moduleinfo->completionsubmit = 0;

        $created = add_moduleinfo($moduleinfo, $course);
        return (int) $created->coursemodule;
    }

    private function validate_item(\stdClass $item): void {
        if (empty($item->externalid) || \core_text::strlen((string) $item->externalid) > 100 ||
                empty($item->name) ||
                !isset($item->maxgrade) || !is_numeric($item->maxgrade) || (float) $item->maxgrade <= 0 ||
                floor((float) $item->maxgrade) !== (float) $item->maxgrade) {
            throw new \UnexpectedValueException('Invalid assign queue item.');
        }
    }
}
