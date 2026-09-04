<?php

namespace tool_modeussync\repository;

defined('MOODLE_INTERNAL') || die();

/** Stores the latest Adapter prototype identity independently of editable course fields. */
final class course_map_repository {
    private const TABLE = 'tool_modeussync_course_map';

    public function get_by_courseid(int $courseid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['courseid' => $courseid]);
        return $record === false ? null : $record;
    }

    /** Returns matching map records keyed by numeric Moodle course ID. */
    public function get_by_rmupids(array $rmupids): array {
        return $this->get_by_values('rmupid', $rmupids);
    }

    /** Loads legacy candidates' mappings in one query, including conflicting RMUP identities. */
    public function get_by_courseids(array $courseids): array {
        return $this->get_by_values('courseid', $courseids);
    }

    private function get_by_values(string $field, array $values): array {
        global $DB;
        if (empty($values)) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($values);
        $records = $DB->get_records_select(self::TABLE, "{$field} {$insql}", $params);
        $maps = [];
        foreach ($records as $record) {
            $maps[(int) $record->courseid] = $record;
        }
        return $maps;
    }

    /** Caller-owned transactions allow the mapping to commit with course creation. */
    public function upsert(int $courseid, string $rmupid, string $prototypeid): \stdClass {
        global $DB;
        $rmupid = trim($rmupid);
        $prototypeid = trim($prototypeid);
        if ($courseid <= 0 || $rmupid === '' || $prototypeid === '' ||
                \core_text::strlen($rmupid) > 255 || \core_text::strlen($prototypeid) > 255) {
            throw new \invalid_parameter_exception('Invalid course mapping identifiers.');
        }
        $DB->get_record('course', ['id' => $courseid], 'id', MUST_EXIST);
        $existing = $this->get_by_courseid($courseid);
        if ($existing !== null && $existing->rmupid === $rmupid && $existing->prototypeid === $prototypeid) {
            return $existing;
        }
        $record = (object) [
            'courseid' => $courseid,
            'rmupid' => $rmupid,
            'prototypeid' => $prototypeid,
            'timemodified' => time(),
        ];
        if ($existing === null) {
            $record->timecreated = $record->timemodified;
            $record->id = $DB->insert_record(self::TABLE, $record);
        } else {
            $record->id = $existing->id;
            $DB->update_record(self::TABLE, $record);
        }
        return $DB->get_record(self::TABLE, ['id' => $record->id], '*', MUST_EXIST);
    }

    public function delete_by_courseid(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }
}
