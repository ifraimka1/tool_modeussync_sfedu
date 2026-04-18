<?php

defined('MOODLE_INTERNAL') || die;

$plugin->version = 2025060300;     // The current plugin version (Date: YYYYMMDDXX).
$plugin->component = 'tool_modeussync'; // Full name of the plugin (used for diagnostics).
$plugin->dependencies = [];
// Старые версии Moodle оритентируются только на этот параметр:
$plugin->requires = 2022112800;     // The minimum version of Moodle // (Moodle 4.1.0 28 November 2022)
$plugin->incompatible = 405; // The plugin will not be installable on any versions of Moodle from this point on