<?php

namespace mod_modeussync\form;

defined('MOODLE_INTERNAL') || die();

/** Administrator form for queueing category-scoped repeat synchronization. */
final class global_sync_form extends \moodleform {

    public function definition() {
        $mform = $this->_form;
        $categories = \core_course_category::make_categories_list();

        $mform->addElement(
            'select',
            'categoryid',
            get_string('globalsynccategory', 'mod_modeussync'),
            $categories
        );
        $mform->setType('categoryid', PARAM_INT);
        $mform->addRule('categoryid', null, 'required', null, 'client');
        $mform->addElement(
            'advcheckbox',
            'includesubcategories',
            get_string('globalsyncincludesubcategories', 'mod_modeussync')
        );
        $mform->setDefault('includesubcategories', 0);
        $this->add_action_buttons(false, get_string('globalsyncsubmit', 'mod_modeussync'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $categoryid = (int) ($data['categoryid'] ?? 0);
        if ($categoryid <= 0 || !\core_course_category::get($categoryid, IGNORE_MISSING, true)) {
            $errors['categoryid'] = get_string('globalsyncinvalidcategory', 'mod_modeussync');
        }

        return $errors;
    }
}
