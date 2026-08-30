<?php

namespace mod_modeussync\local\activity;

use tool_modeussync\local\queue\target_module;

defined('MOODLE_INTERNAL') || die();

/** Strict allow-list for generated activity types. */
final class factory_registry {
    /** @var activity_factory_interface */
    private $assign;

    /** @var activity_factory_interface */
    private $quiz;

    /** @var activity_factory_interface */
    private $workshop;

    public function __construct(
        ?activity_factory_interface $assign = null,
        ?activity_factory_interface $quiz = null,
        ?activity_factory_interface $workshop = null
    ) {
        $this->assign = $assign ?? new assign_factory();
        $this->quiz = $quiz ?? new quiz_factory();
        $this->workshop = $workshop ?? new workshop_factory();
    }

    public function get(string $modulename): activity_factory_interface {
        switch ($modulename) {
            case target_module::ASSIGN:
                return $this->assign;
            case target_module::QUIZ:
                return $this->quiz;
            case target_module::WORKSHOP:
                return $this->workshop;
            default:
                throw new \invalid_parameter_exception('Unsupported target module: ' . $modulename);
        }
    }
}
