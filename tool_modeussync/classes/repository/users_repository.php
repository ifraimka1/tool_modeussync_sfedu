<?php
namespace tool_modeussync\repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Репозиторий для users
 */
class users_repository
{
    /**
     * Maps each requested external person id to every matching Moodle user id.
     *
     * @param array $externalIds external person ids.
     * @return array external id => sorted Moodle user ids.
     */
    public function toInternalIdMap_OneToMany(array $externalIds): array
    {
        global $DB;

        if (empty($externalIds)) {
            return [];
        }

        [$tableName, $idField] = $this->getTableNameWithIdField();
        [$insql, $params] = $DB->get_in_or_equal(
            array_values(array_unique($externalIds)),
            SQL_PARAMS_NAMED,
            'modeusexternal'
        );

        if ($tableName === 'user') {
            $sql = "SELECT id, {$idField} AS extid
                      FROM {user}
                     WHERE {$idField} {$insql}";
        } else {
            $params['idfield'] = $idField;
            $sql = "SELECT u.id, d.data AS extid
                      FROM {user} u
                      JOIN {user_info_data} d ON d.userid = u.id
                     WHERE d.fieldid = :idfield
                       AND d.data {$insql}";
        }

        $map = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $map[(string) $record->extid][] = (int) $record->id;
        }
        foreach ($map as &$userIds) {
            sort($userIds, SORT_NUMERIC);
        }
        unset($userIds);

        return $map;
    }

    /**
     * Maps requested Moodle user ids to external person ids.
     *
     * @param array $userIds Moodle user ids.
     * @return array Moodle user id => external person id.
     */
    public function toExternalIdMap_OneToOne(array $userIds): array
    {
        global $DB;

        if (empty($userIds)) {
            return [];
        }

        [$tableName, $idField] = $this->getTableNameWithIdField();
        [$insql, $params] = $DB->get_in_or_equal(
            array_values(array_unique($userIds)),
            SQL_PARAMS_NAMED,
            'modeususerid'
        );

        if ($tableName === 'user') {
            $sql = "SELECT id, {$idField} AS extid
                      FROM {user}
                     WHERE id {$insql}";
        } else {
            $params['idfield'] = $idField;
            $sql = "SELECT u.id, d.data AS extid
                      FROM {user} u
                      JOIN {user_info_data} d ON d.userid = u.id
                     WHERE d.fieldid = :idfield
                       AND u.id {$insql}";
        }

        $map = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $map[(int) $record->id] = $record->extid;
        }

        return $map;
    }

    public function getUserExternalIdGetter()
    {
        // Идентификатор персоны, по которому мы ищем пользователей, может находиться либо в таблице user, либо в user_info_data.
        // Получаем внешний идентификатор персоны по userid.
        // Где именно находится идентификатор - определяется настройками плагина.
        // $idField содержит либо название колонки таблицы user, либо идентификатор user_info_field для фильтрации записей в user_info_data.
        [$tableName, $idField] = $this->getTableNameWithIdField();

        if ($tableName == "user") {
            mtrace("В качестве сквозного идентификатора для пользователей используется [user]->[$idField]");
            return function ($userid) use ($idField) {
                global $CFG, $DB;
                $user = $DB->get_record('user', ['id' => $userid]);

                if (!$user) {
                    return false;
                } else {
                    return $user->$idField;
                }
            };
        } else {
            mtrace("В качестве сквозного идентификатора для пользователей используется [user_info_field]->[$idField]");
            return function ($userId) use ($idField) {
                global $CFG, $DB;
                $sql = "SELECT u.id, d.data FROM {user} u
                LEFT JOIN {user_info_data} d ON u.id = d.userid
                WHERE u.id = :userid AND d.fieldid = :idfield";
                $user = $DB->get_record_sql($sql, ['userid' => $userId, 'idfield' => $idField]);

                if (!$user) {
                    return false;
                } else {
                    return $user->data;
                }
            };
        }
    }

    public function getUserIdGetter()
    {
        // Идентификатор персоны, по которому мы ищем пользователей, может находиться либо в таблице user, либо в user_info_data.
        // Получаем внутренний идентификатор userid по внешнему идентификатору персоны.
        // Где именно находится идентификатор - определяется настройками плагина.
        // $idField содержит либо название колонки таблицы user, либо идентификатор user_info_field для фильтрации записей в user_info_data.
        [$tableName, $idField] = $this->getTableNameWithIdField();

        if ($tableName == "user") {
            mtrace("В качестве сквозного идентификатора для пользователей используется [user]->[$idField]");
            return function ($externalId) use ($idField) {
                global $CFG, $DB;
                $user = $DB->get_record('user', [$idField => $externalId]);

                if (!$user) {
                    return false;
                } else {
                    return $user->id;
                }
            };
        } else {
            mtrace("В качестве сквозного идентификатора для пользователей используется [user_info_field]->[$idField]");
            return function ($externalId) use ($idField) {
                global $CFG, $DB;
                $sql = "SELECT u.id FROM {user} u
                LEFT JOIN {user_info_data} d ON u.id = d.userid
                WHERE d.fieldid = :idfield AND d.data = :externalid";
                $user = $DB->get_record_sql($sql, ['idfield' => $idField, 'externalid' => $externalId]);

                if (!$user) {
                    return false;
                } else {
                    return $user->id;
                }
            };
        }
    }

    // Получает список полей пользователей из таблицы user_info_field.
    // Используется в настройках плагина.
    public static function get_custom_user_fields(): array
    {
        global $DB;

        $sql = "SELECT CONCAT('user_info_field::', id) as id, CONCAT('user_info_field -> ', shortname, ' (', name, ')') as name from {user_info_field};";

        return $DB->get_records_sql($sql);
    }

    /**
     * Returns the configured source and field used as the external person id.
     *
     * @return array{0: string, 1: string}
     */
    private function getTableNameWithIdField(): array
    {
        global $DB;

        $configuration = get_config('tool_modeussync', 'user_sync_field');
        if (!$configuration || !str_contains($configuration, '::')) {
            throw new \Exception('Не задана настройка user_sync_field');
        }

        [$tableName, $idField] = explode('::', $configuration, 2);
        if ($tableName === 'user') {
            $columns = $DB->get_columns('user');
            if (!isset($columns[$idField])) {
                throw new \Exception("Неизвестное поле пользователя: {$idField}");
            }
        } else if ($tableName === 'user_info_field') {
            if (!ctype_digit((string) $idField)) {
                throw new \Exception("Некорректный идентификатор поля пользователя: {$idField}");
            }
        } else {
            throw new \Exception("Неизвестный источник идентификатора пользователя: {$tableName}");
        }

        return [$tableName, $idField];
    }
}
