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
        $table->addField(new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE));
        $table->addField(new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->addField(new xmldb_field('idmodeus', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL));
        $table->addField(new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending'));
        $table->addField(new xmldb_field('lasterror', XMLDB_TYPE_TEXT));
        $table->addField(new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->addField(new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->addField(new xmldb_field('timesynced', XMLDB_TYPE_INTEGER, '10'));
        $table->addKey(new xmldb_key('primary', XMLDB_KEY_PRIMARY, ['id']));
        $table->addKey(new xmldb_key('course_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']));
        $table->addIndex(new xmldb_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status']));

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('tool_modeussync_queue_items');
        $table->addField(new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE));
        $table->addField(new xmldb_field('queueid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->addField(new xmldb_field('externalid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL));
        $table->addField(new xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL));
        $table->addField(new xmldb_field('maxgrade', XMLDB_TYPE_NUMBER, '10,5', null, XMLDB_NOTNULL));
        $table->addField(new xmldb_field('targetmodule', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'assign'));
        $table->addField(new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending'));
        $table->addField(new xmldb_field('coursemoduleid', XMLDB_TYPE_INTEGER, '10'));
        $table->addField(new xmldb_field('createdby', XMLDB_TYPE_INTEGER, '10'));
        $table->addField(new xmldb_field('lasterror', XMLDB_TYPE_TEXT));
        $table->addField(new xmldb_field('payloadjson', XMLDB_TYPE_TEXT));
        $table->addField(new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->addField(new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->addKey(new xmldb_key('primary', XMLDB_KEY_PRIMARY, ['id']));
        $table->addKey(new xmldb_key('queue_fk', XMLDB_KEY_FOREIGN, ['queueid'], 'tool_modeussync_course_queue', ['id']));
        $table->addKey(new xmldb_key('coursemodule_fk', XMLDB_KEY_FOREIGN, ['coursemoduleid'], 'course_modules', ['id']));
        $table->addKey(new xmldb_key('createdby_fk', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']));
        $table->addIndex(new xmldb_index('queueexternal_uix', XMLDB_INDEX_UNIQUE, ['queueid', 'externalid']));
        $table->addIndex(new xmldb_index('queuestatus_ix', XMLDB_INDEX_NOTUNIQUE, ['queueid', 'status']));

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026071600, 'tool', 'modeussync');
    }

    if ($oldversion < 2026083100) {
        $table = new xmldb_table('tool_modeussync_queue_items');
        $field = new xmldb_field('nameoverride', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'name');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026083100, 'tool', 'modeussync');
    }

    return true;
}
