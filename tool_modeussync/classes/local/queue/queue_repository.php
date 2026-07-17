<?php

namespace tool_modeussync\local\queue;

defined('MOODLE_INTERNAL') || die();

/**
 * The sole DML boundary for the persistent Modeus activity queue.
 */
final class queue_repository {

    /** Moodle course_modules.idnumber capacity. */
    private const COURSE_MODULE_IDNUMBER_MAX_LENGTH = 100;

    /** @var string */
    private const COURSE_QUEUE_TABLE = 'tool_modeussync_course_queue';

    /** @var string */
    private const ITEMS_TABLE = 'tool_modeussync_queue_items';

    /**
     * Gets the queue record for a Moodle course.
     *
     * @param int $courseid Moodle course id.
     * @return \stdClass|null
     */
    public function get_course_queue(int $courseid): ?\stdClass {
        global $DB;

        $record = $DB->get_record(self::COURSE_QUEUE_TABLE, ['courseid' => $courseid]);
        return $record === false ? null : $record;
    }

    /**
     * Gets queue items in deterministic creation order.
     *
     * @param int $queueid Queue id.
     * @return array
     */
    public function get_items(int $queueid): array {
        global $DB;

        return array_values($DB->get_records(self::ITEMS_TABLE, ['queueid' => $queueid], 'id ASC'));
    }

    /**
     * Gets one queue item.
     *
     * @param int $itemid Queue item id.
     * @return \stdClass
     */
    public function get_item(int $itemid): \stdClass {
        global $DB;

        return $DB->get_record(self::ITEMS_TABLE, ['id' => $itemid], '*', MUST_EXIST);
    }

    /**
     * Creates a course queue or updates its Modeus identifier.
     *
     * @param int $courseid Moodle course id.
     * @param string $idmodeus Modeus course id.
     * @return \stdClass
     */
    public function upsert_course_queue(int $courseid, string $idmodeus): \stdClass {
        global $DB;

        $queue = $this->get_course_queue($courseid);
        if ($queue !== null) {
            if ($queue->idmodeus !== $idmodeus) {
                $queue->idmodeus = $idmodeus;
                $queue->timemodified = time();
                $DB->update_record(self::COURSE_QUEUE_TABLE, $queue);
            }
            return $queue;
        }

        $now = time();
        $queue = (object) [
            'courseid' => $courseid,
            'idmodeus' => $idmodeus,
            'status' => course_status::PENDING,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $queue->id = $DB->insert_record(self::COURSE_QUEUE_TABLE, $queue);

        return $queue;
    }

    /**
     * Creates or refreshes an item from the complete external payload.
     *
     * @param int $queueid Queue id.
     * @param array $item External item payload.
     * @return array [queue item record, whether it changed].
     */
    public function upsert_item(int $queueid, array $item): array {
        global $DB;

        $payloadjson = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadjson === false) {
            throw new \UnexpectedValueException('Queue item payload cannot be JSON encoded.');
        }

        $externalid = $this->trim_scalar($item['id'] ?? null);
        $name = $this->trim_scalar($item['name'] ?? null);
        $grade = $item['grade'] ?? null;
        $validgrade = is_numeric($grade) && (float) $grade > 0;
        $validexternalid = $externalid !== '' &&
            \core_text::strlen($externalid) <= self::COURSE_MODULE_IDNUMBER_MAX_LENGTH;
        $valid = $validexternalid && $name !== '' && $validgrade;

        if (!$validexternalid) {
            $externalid = 'invalid:' . hash('sha256', $externalid === '' ? $payloadjson : $externalid);
        }

        $existing = $DB->get_record(self::ITEMS_TABLE, [
            'queueid' => $queueid,
            'externalid' => $externalid,
        ]);
        if ($existing !== false && $existing->status === item_status::CREATED) {
            if ($existing->payloadjson === $payloadjson) {
                return [$existing, false];
            }

            $existing->payloadjson = $payloadjson;
            $existing->timemodified = time();
            $DB->update_record(self::ITEMS_TABLE, (object) [
                'id' => $existing->id,
                'payloadjson' => $existing->payloadjson,
                'timemodified' => $existing->timemodified,
            ]);

            return [$existing, true];
        }

        $values = $this->item_values($name, $grade, $valid, $payloadjson);
        if ($existing === false) {
            $now = time();
            $record = (object) array_merge([
                'queueid' => $queueid,
                'externalid' => $externalid,
                'targetmodule' => target_module::DEFAULT,
                'timecreated' => $now,
                'timemodified' => $now,
            ], $values);
            $record->id = $DB->insert_record(self::ITEMS_TABLE, $record);
            return [$record, true];
        }

        if (!$this->item_needs_update($existing, $values)) {
            return [$existing, false];
        }

        foreach ($values as $field => $value) {
            $existing->{$field} = $value;
        }
        $existing->timemodified = time();
        $DB->update_record(self::ITEMS_TABLE, $existing);

        return [$existing, true];
    }

    /**
     * Checks whether a course has any queue items.
     *
     * @param int $courseid Moodle course id.
     * @return bool
     */
    public function course_has_items(int $courseid): bool {
        global $DB;

        $sql = "SELECT qi.id
                  FROM {" . self::ITEMS_TABLE . "} qi
                  JOIN {" . self::COURSE_QUEUE_TABLE . "} cq ON cq.id = qi.queueid
                 WHERE cq.courseid = :courseid";
        return $DB->record_exists_sql($sql, ['courseid' => $courseid]);
    }

    /**
     * Updates the course queue status and clears stale sync timestamps as needed.
     *
     * @param int $queueid Queue id.
     * @param string $status New queue status.
     * @param string|null $error Error associated with the new status.
     * @return void
     */
    public function set_course_status(int $queueid, string $status, ?string $error = null): void {
        global $DB;

        $allowedstatuses = [
            course_status::PENDING,
            course_status::PROCESSING,
            course_status::AWAITING_SYNC,
            course_status::SYNC_FAILED,
        ];
        if (!in_array($status, $allowedstatuses, true)) {
            throw new \invalid_parameter_exception('Unsupported course queue status transition.');
        }

        $record = (object) [
            'id' => $queueid,
            'status' => $status,
            'lasterror' => $error,
            'timemodified' => time(),
        ];
        $record->timesynced = null;
        $DB->update_record(self::COURSE_QUEUE_TABLE, $record);
    }

    /**
     * Marks a queue as fully synced at a supplied time.
     *
     * @param int $queueid Queue id.
     * @param int $timesynced Sync completion time.
     * @return void
     */
    public function mark_course_synced(int $queueid, int $timesynced): void {
        global $DB;

        $DB->update_record(self::COURSE_QUEUE_TABLE, (object) [
            'id' => $queueid,
            'status' => course_status::SYNCED,
            'lasterror' => null,
            'timesynced' => $timesynced,
            'timemodified' => time(),
        ]);
    }

    /**
     * Updates an item status and its corresponding error state.
     *
     * @param int $itemid Queue item id.
     * @param string $status New item status.
     * @param string|null $error Error associated with the new status.
     * @return void
     */
    public function set_item_status(int $itemid, string $status, ?string $error = null): void {
        global $DB;

        $allowedstatuses = [item_status::PENDING, item_status::PROCESSING, item_status::FAILED];
        if (!in_array($status, $allowedstatuses, true)) {
            throw new \invalid_parameter_exception('Unsupported queue item status transition.');
        }

        $item = $this->get_item($itemid);
        if ($item->status === item_status::CREATED) {
            throw new \invalid_parameter_exception('A created queue item cannot be reopened by a generic transition.');
        }

        $DB->update_record(self::ITEMS_TABLE, (object) [
            'id' => $itemid,
            'status' => $status,
            'lasterror' => $error,
            'timemodified' => time(),
        ]);
    }

    /**
     * Marks an item as created in Moodle.
     *
     * @param int $itemid Queue item id.
     * @param int $cmid Moodle course-module id.
     * @param int $createdby Moodle user id.
     * @param string $actualmodule Module actually created.
     * @return void
     */
    public function mark_item_created(int $itemid, int $cmid, int $createdby, string $actualmodule): void {
        global $DB;

        if (!target_module::is_supported($actualmodule)) {
            throw new \invalid_parameter_exception('Unsupported created activity module.');
        }

        $DB->update_record(self::ITEMS_TABLE, (object) [
            'id' => $itemid,
            'coursemoduleid' => $cmid,
            'createdby' => $createdby,
            'targetmodule' => $actualmodule,
            'status' => item_status::CREATED,
            'lasterror' => null,
            'timemodified' => time(),
        ]);
    }

    /**
     * Reopens a previously created item after its Moodle activity disappeared or became invalid.
     *
     * This explicit transition is intentionally separate from set_item_status(), which must not
     * reopen created records during ordinary state updates.
     *
     * @param int $itemid Queue item id.
     * @param string $status Pending when missing, failed when an idnumber conflict exists.
     * @param string|null $error Safe reconciliation error.
     * @return void
     */
    public function reopen_created_item(
        int $itemid,
        string $status = item_status::PENDING,
        ?string $error = null
    ): void {
        global $DB;

        if ($status !== item_status::PENDING && $status !== item_status::FAILED) {
            throw new \invalid_parameter_exception('A reopened queue item must be pending or failed.');
        }
        $item = $this->get_item($itemid);
        if ($item->status !== item_status::CREATED) {
            throw new \invalid_parameter_exception('Only a created queue item can be reopened by reconciliation.');
        }

        $DB->update_record(self::ITEMS_TABLE, (object) [
            'id' => $itemid,
            'status' => $status,
            'coursemoduleid' => null,
            'createdby' => null,
            'lasterror' => $error,
            'timemodified' => time(),
        ]);
    }

    /**
     * Reopens the queue item linked to a deleted generated Moodle activity.
     *
     * @param int $cmid Deleted course-module id.
     * @return void
     */
    public function reopen_item_by_course_module(int $cmid): void {
        global $DB;

        $item = $DB->get_record(self::ITEMS_TABLE, [
            'coursemoduleid' => $cmid,
            'status' => item_status::CREATED,
        ]);
        if ($item === false) {
            return;
        }
        $queue = $DB->get_record(self::COURSE_QUEUE_TABLE, ['id' => $item->queueid]);
        if ($queue === false) {
            return;
        }
        $lock = $this->acquire_course_lock((int) $queue->courseid);
        try {
            $item = $DB->get_record(self::ITEMS_TABLE, [
                'coursemoduleid' => $cmid,
                'status' => item_status::CREATED,
            ]);
            if ($item !== false) {
                $this->reopen_created_item((int) $item->id);
                $this->set_course_status((int) $item->queueid, course_status::PENDING);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Persists editor target-module choices for pending or failed items only.
     *
     * @param int $queueid Queue id.
     * @param array $selections Item-id to target-module map.
     * @return void
     */
    public function save_target_modules(int $queueid, array $selections): void {
        global $DB;

        foreach ($selections as $itemid => $module) {
            if (filter_var($itemid, FILTER_VALIDATE_INT) === false || (int) $itemid <= 0) {
                throw new \invalid_parameter_exception('Queue item ids must be positive integers.');
            }
            if (!is_string($module) || !target_module::is_supported($module)) {
                throw new \invalid_parameter_exception('Unsupported queue target module.');
            }

            $item = $DB->get_record(self::ITEMS_TABLE, ['id' => (int) $itemid]);
            if ($item === false || (int) $item->queueid !== $queueid) {
                throw new \invalid_parameter_exception('Queue item does not belong to this queue.');
            }
            if ($item->status !== item_status::PENDING && $item->status !== item_status::FAILED) {
                continue;
            }
            if ($item->targetmodule === $module) {
                continue;
            }

            $DB->update_record(self::ITEMS_TABLE, (object) [
                'id' => $item->id,
                'targetmodule' => $module,
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Gets course ids that currently have queue items.
     *
     * @param int $limit Maximum number of course ids.
     * @return array
     */
    public function get_course_ids_with_items(int $limit = 100): array {
        global $DB;

        $sql = "SELECT cq.courseid
                  FROM {" . self::COURSE_QUEUE_TABLE . "} cq
                  JOIN {" . self::ITEMS_TABLE . "} qi ON qi.queueid = cq.id
              GROUP BY cq.courseid
              ORDER BY cq.courseid ASC";
        return $DB->get_fieldset_sql($sql, [], 0, max(1, $limit));
    }

    /**
     * Removes queue state owned by a course that is being deleted.
     *
     * @param int $courseid Moodle course id.
     * @return void
     */
    public function delete_course_queue(int $courseid): void {
        global $DB;

        $queue = $this->get_course_queue($courseid);
        if ($queue === null) {
            return;
        }
        $lock = $this->acquire_course_lock($courseid);
        try {
            $queue = $this->get_course_queue($courseid);
            if ($queue === null) {
                return;
            }
            $transaction = $DB->start_delegated_transaction();
            $DB->delete_records(self::ITEMS_TABLE, ['queueid' => $queue->id]);
            $DB->delete_records(self::COURSE_QUEUE_TABLE, ['id' => $queue->id]);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /**
     * Clears personal creator references without removing assignment queue data.
     *
     * @param int $courseid Moodle course id.
     * @param array|null $userids Specific users, or null for every user in the course context.
     * @return void
     */
    public function clear_createdby(int $courseid, ?array $userids = null): void {
        global $DB;

        if ($userids !== null && empty($userids)) {
            return;
        }
        $queue = $this->get_course_queue($courseid);
        if ($queue === null) {
            return;
        }
        $lock = $this->acquire_course_lock($courseid);
        try {
            $queue = $this->get_course_queue($courseid);
            if ($queue === null) {
                return;
            }
            $select = 'queueid = :queueid AND createdby IS NOT NULL';
            $params = ['queueid' => $queue->id];
            if ($userids !== null) {
                [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'privacyuserid');
                $select .= ' AND createdby ' . $insql;
                $params += $inparams;
            }
            $DB->set_field_select(self::ITEMS_TABLE, 'createdby', null, $select, $params);
        } finally {
            $lock->release();
        }
    }

    /** @return \core\lock\lock Acquired shared per-course queue lock. */
    private function acquire_course_lock(int $courseid): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('tool_modeussync');
        $lock = $factory->get_lock('queue:' . $courseid, 30);
        if (!$lock) {
            throw new \moodle_exception('cannotacquirequeuelock', 'tool_modeussync');
        }

        return $lock;
    }

    /**
     * Converts a source field to a trimmed scalar string.
     *
     * @param mixed $value Source value.
     * @return string
     */
    private function trim_scalar($value): string {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Builds canonical values for a queue item state.
     *
     * @param string $name Trimmed source name.
     * @param mixed $grade Source grade.
     * @param bool $valid Whether the source item is valid.
     * @param string $payloadjson Complete JSON payload.
     * @return array
     */
    private function item_values(string $name, $grade, bool $valid, string $payloadjson): array {
        if ($valid) {
            return [
                'name' => $name,
                'maxgrade' => (float) $grade,
                'status' => item_status::PENDING,
                'lasterror' => null,
                'payloadjson' => $payloadjson,
            ];
        }

        return [
            'name' => get_string('invalidassignmentname', 'tool_modeussync'),
            'maxgrade' => is_numeric($grade) ? (float) $grade : 0,
            'status' => item_status::FAILED,
            'lasterror' => get_string('invalidassignmentpayload', 'tool_modeussync'),
            'payloadjson' => $payloadjson,
        ];
    }

    /**
     * Detects whether a non-created item needs persistence.
     *
     * @param \stdClass $existing Stored record.
     * @param array $values Canonical source values.
     * @return bool
     */
    private function item_needs_update(\stdClass $existing, array $values): bool {
        return $existing->name !== $values['name'] ||
            (float) $existing->maxgrade !== (float) $values['maxgrade'] ||
            $existing->status !== $values['status'] ||
            $existing->lasterror !== $values['lasterror'] ||
            $existing->payloadjson !== $values['payloadjson'];
    }
}
