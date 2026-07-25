<?php

defined('MOODLE_INTERNAL') || die();

$tasks = [[
    'classname' => '\mod_modeussync\task\ensure_instances',
    'blocking' => 0,
    'minute' => '*/5',
    'hour' => '*',
    'day' => '*',
    'month' => '*',
    'dayofweek' => '*',
]];
