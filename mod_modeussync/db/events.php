<?php

defined('MOODLE_INTERNAL') || die();

$observers = [[
    'eventname' => '\tool_modeussync\event\assignments_queued',
    'callback' => '\mod_modeussync\observer::assignments_queued',
]];
