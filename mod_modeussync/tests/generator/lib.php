<?php

defined('MOODLE_INTERNAL') || die();

/** Test generator using the same guarded system path as production instance creation. */
final class mod_modeussync_generator extends testing_module_generator {

    public function create_instance($record = null, array $options = null) {
        $record = (object) (array) $record;
        if (empty($record->name)) {
            $record->name = 'Задания из Modeus';
        }
        if (!isset($record->intro)) {
            $record->intro = '';
        }
        if (!isset($record->introformat)) {
            $record->introformat = FORMAT_HTML;
        }

        return \mod_modeussync\local\instance_guard::run_system_add(function() use ($record, $options) {
            return parent::create_instance($record, $options);
        });
    }
}
