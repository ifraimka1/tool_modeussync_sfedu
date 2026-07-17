<?php

require_once('../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$PAGE->set_url('/mod/modeussync/index.php', ['id' => $course->id]);
$PAGE->set_context(context_course::instance($course->id));
$PAGE->set_title(get_string('modulenameplural', 'mod_modeussync'));
$PAGE->set_heading(format_string($course->fullname));

$instances = get_all_instances_in_course('modeussync', $course);
if (count($instances) === 1) {
    $instance = reset($instances);
    redirect(new moodle_url('/mod/modeussync/view.php', ['id' => $instance->coursemodule]));
}

$table = new html_table();
$table->head = [get_string('name')];
foreach ($instances as $instance) {
    $table->data[] = [[
        'data' => html_writer::link(
            new moodle_url('/mod/modeussync/view.php', ['id' => $instance->coursemodule]),
            format_string($instance->name)
        ),
    ]];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_modeussync'));
echo html_writer::table($table);
echo $OUTPUT->footer();
