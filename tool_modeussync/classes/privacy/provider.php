<?php

namespace tool_modeussync\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_modeussync\local\queue\queue_repository;

defined('MOODLE_INTERNAL') || die();

/** Privacy API provider for the queue item's createdby audit reference. */
final class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'tool_modeussync_queue_items',
            [
                'createdby' => 'privacy:metadata:queueitems:createdby',
            ],
            'privacy:metadata:queueitems'
        );

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {tool_modeussync_course_queue} cq ON cq.courseid = ctx.instanceid
                  JOIN {tool_modeussync_queue_items} qi ON qi.queueid = cq.id
                 WHERE ctx.contextlevel = :contextlevel
                   AND qi.createdby = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }
            $sql = "SELECT qi.externalid, qi.name, qi.targetmodule, qi.status, qi.timemodified
                      FROM {tool_modeussync_queue_items} qi
                      JOIN {tool_modeussync_course_queue} cq ON cq.id = qi.queueid
                     WHERE cq.courseid = :courseid
                       AND qi.createdby = :userid
                  ORDER BY qi.id ASC";
            $records = array_values($DB->get_records_sql($sql, [
                'courseid' => $context->instanceid,
                'userid' => $user->id,
            ]));
            if (!empty($records)) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:exportpath', 'tool_modeussync')],
                    (object) ['items' => $records]
                );
            }
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel === CONTEXT_COURSE) {
            self::clear_createdby((int) $context->instanceid);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_COURSE) {
                self::clear_createdby((int) $context->instanceid, [$userid]);
            }
        }
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        $sql = "SELECT qi.createdby
                  FROM {tool_modeussync_queue_items} qi
                  JOIN {tool_modeussync_course_queue} cq ON cq.id = qi.queueid
                 WHERE cq.courseid = :courseid
                   AND qi.createdby IS NOT NULL";
        $userlist->add_from_sql('createdby', $sql, ['courseid' => $context->instanceid]);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel === CONTEXT_COURSE) {
            self::clear_createdby((int) $context->instanceid, $userlist->get_userids());
        }
    }

    private static function clear_createdby(int $courseid, ?array $userids = null): void {
        (new queue_repository())->clear_createdby($courseid, $userids);
    }
}
