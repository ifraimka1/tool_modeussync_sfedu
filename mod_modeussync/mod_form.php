<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/** Standard form is edit-only; system creation bypasses it through add_moduleinfo. */
final class mod_modeussync_mod_form extends moodleform_mod {

    public function definition(): void {
        if (empty($this->current->instance) &&
                !\mod_modeussync\local\instance_guard::is_system_add_allowed()) {
            throw new moodle_exception('manualcreationdisabled', 'mod_modeussync');
        }

        $mform = $this->_form;
        $mform->addElement('text', 'name', get_string('modeussyncname', 'mod_modeussync'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}
