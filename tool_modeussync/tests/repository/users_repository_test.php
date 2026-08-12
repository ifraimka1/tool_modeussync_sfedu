<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\repository\users_repository;

/**
 * Tests batch conversion between Moodle user ids and Modeus person ids.
 */
final class users_repository_test extends advanced_testcase {

    public function test_empty_inputs_return_empty_maps(): void {
        $this->resetAfterTest();
        set_config('user_sync_field', 'user::idnumber', 'tool_modeussync');

        $repository = new users_repository();

        $this->assertSame([], $repository->toInternalIdMap_OneToMany([]));
        $this->assertSame([], $repository->toExternalIdMap_OneToOne([]));
    }

    public function test_user_column_maps_duplicate_external_ids_and_reverse_ids(): void {
        $this->resetAfterTest();
        set_config('user_sync_field', 'user::idnumber', 'tool_modeussync');
        $first = $this->getDataGenerator()->create_user(['idnumber' => 'person-1']);
        $second = $this->getDataGenerator()->create_user(['idnumber' => 'person-1']);
        $third = $this->getDataGenerator()->create_user(['idnumber' => 'person-2']);

        $repository = new users_repository();
        $internal = $repository->toInternalIdMap_OneToMany(['person-1', 'person-2', 'missing']);

        $this->assertSame(
            [(int) min($first->id, $second->id), (int) max($first->id, $second->id)],
            $internal['person-1']
        );
        $this->assertSame([(int) $third->id], $internal['person-2']);
        $this->assertArrayNotHasKey('missing', $internal);
        $this->assertSame([
            (int) $first->id => 'person-1',
            (int) $third->id => 'person-2',
        ], $repository->toExternalIdMap_OneToOne([$first->id, $third->id]));
    }

    public function test_custom_profile_field_maps_duplicate_external_ids_and_reverse_ids(): void {
        global $DB;

        $this->resetAfterTest();
        $categoryid = $DB->insert_record('user_info_category', (object) [
            'name' => 'Modeus test fields',
            'sortorder' => 1,
        ]);
        $fieldid = $DB->insert_record('user_info_field', (object) [
            'shortname' => 'modeuspersonid',
            'name' => 'Modeus person id',
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'categoryid' => $categoryid,
            'sortorder' => 1,
            'required' => 0,
            'locked' => 0,
            'visible' => 2,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => FORMAT_HTML,
            'param1' => '30',
            'param2' => '2048',
            'param3' => '0',
            'param4' => '',
            'param5' => '',
        ]);
        set_config('user_sync_field', 'user_info_field::' . $fieldid, 'tool_modeussync');

        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $third = $this->getDataGenerator()->create_user();
        foreach ([
            [$first->id, 'custom-person-1'],
            [$second->id, 'custom-person-1'],
            [$third->id, 'custom-person-2'],
        ] as [$userid, $externalid]) {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $userid,
                'fieldid' => $fieldid,
                'data' => $externalid,
                'dataformat' => FORMAT_PLAIN,
            ]);
        }

        $repository = new users_repository();
        $internal = $repository->toInternalIdMap_OneToMany([
            'custom-person-1',
            'custom-person-2',
            'missing',
        ]);

        $this->assertSame(
            [(int) min($first->id, $second->id), (int) max($first->id, $second->id)],
            $internal['custom-person-1']
        );
        $this->assertSame([(int) $third->id], $internal['custom-person-2']);
        $this->assertArrayNotHasKey('missing', $internal);
        $this->assertSame([
            (int) $first->id => 'custom-person-1',
            (int) $third->id => 'custom-person-2',
        ], $repository->toExternalIdMap_OneToOne([$first->id, $third->id]));
    }
}
