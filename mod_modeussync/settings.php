<?php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('modsettings', new admin_externalpage(
        'mod_modeussync_globalsync',
        get_string('globalsyncpage', 'mod_modeussync'),
        new moodle_url('/mod/modeussync/global_sync.php'),
        'moodle/site:config'
    ));
}
