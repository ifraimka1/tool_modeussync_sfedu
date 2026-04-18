<?php
namespace tool_modeussync\repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Репозиторий для users
 */
class users_repository
{
    public function toInternalIdMap_OneToMany($externalIds): array
    {
        if (count($externalIds) == 0) {
            return [];
        }

        [$tableName, $idField] = $this->getTableNameWithIdField();
        global $CFG, $DB;
        [$insql, $params] = $DB->get_in_or_equal($externalIds, SQL_PARAMS_NAMED, 'rangesearch');

        if ($tableName == "user") {
            mtrace("Ищем внутренние id пользователей user->$idField");
            $sql = "SELECT id, $idField extid FROM {user} WHERE ($idField $insql)";
        } else {
            mtrace("Ищем внутренние id пользователей user_info_field->id=$idField");
            $params['idfield'] = $idField;
            $sql = "SELECT u.id, d.data extid FROM {user} u
                LEFT JOIN {user_info_data} d ON u.id = d.userid
                WHERE d.fieldid = :idfield AND (d.data $insql)";
        }

        $list = $DB->get_records_sql($sql, $params);

        $map = [];
        foreach ($list as $element) {
            $map[$element->extid][] = $element->id;
        }

        return $map;
    }

    public function toExternalIdMap_OneToOne($userIds)
    {
        if (count($userIds) == 0) {
            return [];
        }

        [$tableName, $idField] = $this->getTableNameWithIdField();
        global $CFG, $DB;
        [$insql, $params] = $DB->get_in_or_equal($userIds, SQL_PARAMS_NAMED, 'rangesearch');

        if ($tableName == "user") {
            mtrace("Ищем внешние id пользователей user->$idField");
            $sql = "SELECT id, $idField extid FROM {user} WHERE (id $insql)";
        } else {
            mtrace("Ищем внешние id пользователей user_info_field->id=$idField");
            $params['idfield'] = $idField;
            $sql = "SELECT u.id, d.data extid FROM {user} u
                LEFT JOIN {user_info_data} d ON u.id = d.userid
                WHERE d.fieldid = :idfield AND (u.id $insql)";
        }

        $list = $DB->get_records_sql($sql, $params);

        $map = [];
        foreach ($list as $element) {
            $map[$element->id] = $element->extid;
        }

        return $map;
    }

    // Получает список полей пользователей из таблицы user_info_field.
    // Используется в настройках плагина.
    public static function get_custom_user_fields(): array
    {
        global $DB;

        $sql = "SELECT CONCAT('user_info_field::', id) as id, CONCAT('user_info_field -> ', shortname, ' (', name, ')') as name from {user_info_field};";

        return $DB->get_records_sql($sql);
    }

    private function getTableNameWithIdField(): array
    {
        // Идентификатор персоны, по которому мы ищем пользователей, может находиться либо в таблице user, либо в user_info_data.
        // Получаем внутренний идентификатор userid по внешнему идентификатору персоны.
        // Где именно находится идентификатор - определяется настройками плагина.
        $userIdConfiguration = get_config('tool_modeussync', 'user_sync_field');
        if (!$userIdConfiguration) {
            throw new \Exception("Не задана настройка user_sync_field");
        }

        // $idField содержит либо название колонки таблицы user, либо идентификатор user_info_field для фильтрации записей в user_info_data.
        return explode('::', $userIdConfiguration, 2);
    }
}
