<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

use mod_modeussync\form\global_sync_form;
use mod_modeussync\local\global_sync\task_scheduler;

admin_externalpage_setup('mod_modeussync_globalsync');
require_capability('moodle/site:config', context_system::instance());

$url = new moodle_url('/mod/modeussync/global_sync.php');
$PAGE->set_url($url);
$PAGE->set_title(get_string('globalsyncpage', 'mod_modeussync'));
$PAGE->set_heading($SITE->fullname);
$form = new global_sync_form($url);

if ($data = $form->get_data()) {
    try {
        (new task_scheduler())->queue(
            (int) $data->categoryid,
            !empty($data->includesubcategories)
        );
    } catch (\Throwable $exception) {
        error_log('[mod_modeussync global sync] queue task: ' . get_class($exception));
        redirect(
            $url,
            get_string('globalsyncqueuefailed', 'mod_modeussync'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    redirect(
        $url,
        get_string('globalsyncqueued', 'mod_modeussync'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('globalsyncpage', 'mod_modeussync'));
echo $OUTPUT->box(s(get_string('globalsyncdescription', 'mod_modeussync')), 'generalbox mb-3');
$form->display();
echo $OUTPUT->footer();
