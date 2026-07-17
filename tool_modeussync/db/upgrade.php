<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the tool_modeussync plugin.
 *
 * @param int $oldversion Previous plugin version.
 * @return bool
 */
function xmldb_tool_modeussync_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026071600) {
        $table = new xmldb_table('tool_modeussync_course_queue');
        $table->add_field(new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE));
        $table->add_field(new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->add_field(new xmldb_field('idmodeus', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL));
        $table->add_field(new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending'));
        $table->add_field(new xmldb_field('lasterror', XMLDB_TYPE_TEXT));
        $table->add_field(new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->add_field(new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->add_field(new xmldb_field('timesynced', XMLDB_TYPE_INTEGER, '10'));
        $table->add_key(new xmldb_key('primary', XMLDB_KEY_PRIMARY, ['id']));
        $table->add_key(new xmldb_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']));
        $table->add_index(new xmldb_index('course_uix', XMLDB_INDEX_UNIQUE, ['courseid']));
        $table->add_index(new xmldb_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status']));

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('tool_modeussync_queue_items');
        $table->add_field(new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE));
        $table->add_field(new xmldb_field('queueid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->add_field(new xmldb_field('externalid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL));
        $table->add_field(new xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL));
        $table->add_field(new xmldb_field('maxgrade', XMLDB_TYPE_NUMBER, '10,5', null, XMLDB_NOTNULL));
        $table->add_field(new xmldb_field('targetmodule', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'assign'));
        $table->add_field(new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending'));
        $table->add_field(new xmldb_field('coursemoduleid', XMLDB_TYPE_INTEGER, '10'));
        $table->add_field(new xmldb_field('createdby', XMLDB_TYPE_INTEGER, '10'));
        $table->add_field(new xmldb_field('lasterror', XMLDB_TYPE_TEXT));
        $table->add_field(new xmldb_field('payloadjson', XMLDB_TYPE_TEXT));
        $table->add_field(new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->add_field(new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->add_key(new xmldb_key('primary', XMLDB_KEY_PRIMARY, ['id']));
        $table->add_key(new xmldb_key('queue_fk', XMLDB_KEY_FOREIGN, ['queueid'], 'tool_modeussync_course_queue', ['id']));
        $table->add_key(new xmldb_key('coursemodule_fk', XMLDB_KEY_FOREIGN, ['coursemoduleid'], 'course_modules', ['id']));
        $table->add_key(new xmldb_key('createdby_fk', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']));
        $table->add_index(new xmldb_index('queueexternal_uix', XMLDB_INDEX_UNIQUE, ['queueid', 'externalid']));
        $table->add_index(new xmldb_index('queuestatus_ix', XMLDB_INDEX_NOTUNIQUE, ['queueid', 'status']));

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026071600, 'tool', 'modeussync');
    }

    return true;
}
