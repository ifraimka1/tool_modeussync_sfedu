<?php

require_once('../../config.php');

use mod_modeussync\local\access;
use mod_modeussync\local\activity\creation_service;
use mod_modeussync\output\queue_page;
use tool_modeussync\local\queue\queue_repository;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('modeussync', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('modeussync', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
access::require_view($context);

$PAGE->set_url('/mod/modeussync/view.php', ['id' => $cm->id]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));

if (data_submitted() && optional_param('action', '', PARAM_ALPHA) === 'create') {
    access::require_create_request($context, (int) $course->id);
    $selections = optional_param_array('targetmodule', [], PARAM_ALPHANUMEXT);
    $result = (new creation_service())->process((int) $course->id, (int) $USER->id, $selections);
    redirect($PAGE->url, get_string('processresult_' . $result->status, 'mod_modeussync'));
}

$repository = new queue_repository();
$queue = $repository->get_course_queue((int) $course->id);
if ($queue === null) {
    throw new moodle_exception('queuenotfound', 'mod_modeussync');
}
$items = $repository->get_items($queue->id);
$canmanage = access::can_manage($context, (int) $course->id);
$page = new queue_page($queue, $items, $PAGE->url, $canmanage);
/** @var mod_modeussync_renderer $renderer */
$renderer = $PAGE->get_renderer('mod_modeussync');

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));
echo $renderer->render_queue_page($page);
echo $OUTPUT->footer();
