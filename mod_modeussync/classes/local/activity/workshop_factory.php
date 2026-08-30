<?php

namespace mod_modeussync\local\activity;

defined('MOODLE_INTERNAL') || die();

/** Creates an empty workshop whose submission grade comes from Modeus. */
final class workshop_factory implements activity_factory_interface {

    public function create(\stdClass $course, int $sectionnum, \stdClass $item): int {
        global $CFG, $DB;

        $this->validate_item($item);
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/workshop/lib.php');

        $module = $DB->get_record('modules', ['name' => 'workshop'], '*', MUST_EXIST);
        $moduleinfo = new \stdClass();
        $moduleinfo->course = $course->id;
        $moduleinfo->module = $module->id;
        $moduleinfo->modulename = 'workshop';
        $moduleinfo->add = 'workshop';
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
        $moduleinfo->gradinggrade = 0;
        $moduleinfo->strategy = 'accumulative';
        $moduleinfo->evaluation = 'best';
        $moduleinfo->gradedecimals = 2;
        $moduleinfo->useexamples = 0;
        $moduleinfo->usepeerassessment = 1;
        $moduleinfo->useselfassessment = 0;
        $moduleinfo->submissiontypetext = 2;
        $moduleinfo->submissiontypefile = 1;
        $moduleinfo->nattachments = 1;
        $moduleinfo->submissionfiletypes = '';
        $moduleinfo->maxbytes = 0;
        $moduleinfo->examplesmode = 0;
        $moduleinfo->latesubmissions = 0;
        $moduleinfo->submissionstart = 0;
        $moduleinfo->submissionend = 0;
        $moduleinfo->assessmentstart = 0;
        $moduleinfo->assessmentend = 0;
        $moduleinfo->phaseswitchassessment = 0;
        $moduleinfo->overallfeedbackmode = 0;
        $moduleinfo->overallfeedbackfiles = 0;
        $moduleinfo->overallfeedbackfiletypes = '';
        $moduleinfo->overallfeedbackmaxbytes = 0;
        $moduleinfo->instructauthors = '';
        $moduleinfo->instructauthorsformat = FORMAT_HTML;
        $moduleinfo->instructreviewers = '';
        $moduleinfo->instructreviewersformat = FORMAT_HTML;
        $moduleinfo->conclusion = '';
        $moduleinfo->conclusionformat = FORMAT_HTML;

        $created = add_moduleinfo($moduleinfo, $course);
        return (int) $created->coursemodule;
    }

    private function validate_item(\stdClass $item): void {
        if (empty($item->externalid) || \core_text::strlen((string) $item->externalid) > 100 ||
                empty($item->name) ||
                !isset($item->maxgrade) || !is_numeric($item->maxgrade) || (float) $item->maxgrade <= 0) {
            throw new \UnexpectedValueException('Invalid workshop queue item.');
        }
    }
}
