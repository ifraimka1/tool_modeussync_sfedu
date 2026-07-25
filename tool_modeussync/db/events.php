<?php

defined('MOODLE_INTERNAL') || die();

$observers = [[
    'eventname' => '\core\event\course_deleted',
    'callback' => '\tool_modeussync\observer::course_deleted',
], [
    'eventname' => '\core\event\course_module_deleted',
    'callback' => '\tool_modeussync\observer::course_module_deleted',
]];
