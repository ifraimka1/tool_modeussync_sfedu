<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\activity\assign_factory;
use mod_modeussync\local\activity\activity_factory_interface;
use mod_modeussync\local\activity\factory_registry;
use mod_modeussync\local\activity\section_manager;
use tool_modeussync\local\queue\target_module;

/** Registry identity stub; creation is outside this unit test. */
final class stub_activity_factory implements activity_factory_interface {
    public function create(stdClass $course, int $sectionnum, stdClass $item): int {
        throw new coding_exception('Registry stub must not create activities.');
    }
}

/** Tests Modeus assign creation and registry allow-list behavior. */
final class assign_factory_test extends advanced_testcase {

    public function test_factory_creates_assign_with_external_id_and_max_grade(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $sectionnum = (new section_manager())->get_or_create($course->id);
        $item = (object) [
            'externalid' => 'meeting-assign-1',
            'name' => 'Практическая работа',
            'maxgrade' => 37,
        ];

        $cmid = (new assign_factory())->create($course, $sectionnum, $item);
        $cm = get_coursemodule_from_id('assign', $cmid, $course->id, false, MUST_EXIST);
        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $gradeitem = $DB->get_record('grade_items', [
            'courseid' => $course->id,
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
        ], '*', MUST_EXIST);

        $this->assertSame('meeting-assign-1', $cm->idnumber);
        $this->assertSame('Практическая работа', $assign->name);
        $this->assertEquals(37.0, (float) $assign->grade);
        $this->assertEquals(37.0, (float) $gradeitem->grademax);
    }

    public function test_factory_enables_only_file_submissions_with_site_defaults(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('maxfiles', 7, 'assignsubmission_file');
        set_config('maxbytes', 1048576, 'assignsubmission_file');
        set_config('filetypes', '.pdf,.docx', 'assignsubmission_file');
        $course = $this->getDataGenerator()->create_course();
        $sectionnum = (new section_manager())->get_or_create($course->id);
        $item = (object) [
            'externalid' => 'file-submission-assign',
            'name' => 'Задание с ответом в виде файла',
            'maxgrade' => 20,
        ];

        $cmid = (new assign_factory())->create($course, $sectionnum, $item);
        $cm = get_coursemodule_from_id('assign', $cmid, $course->id, false, MUST_EXIST);
        $fileconfig = $DB->get_records_menu('assign_plugin_config', [
            'assignment' => $cm->instance,
            'plugin' => 'file',
            'subtype' => 'assignsubmission',
        ], '', 'name,value');
        $onlinetextenabled = $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $cm->instance,
            'plugin' => 'onlinetext',
            'subtype' => 'assignsubmission',
            'name' => 'enabled',
        ], MUST_EXIST);

        $this->assertSame('1', $fileconfig['enabled']);
        $this->assertSame('7', $fileconfig['maxfilesubmissions']);
        $this->assertSame('1048576', $fileconfig['maxsubmissionsizebytes']);
        $this->assertSame('.pdf,.docx', $fileconfig['filetypeslist']);
        $this->assertSame('0', $onlinetextenabled);
    }

    public function test_factory_rejects_fractional_assign_grade_instead_of_rounding_it(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $sectionnum = (new section_manager())->get_or_create($course->id);
        $item = (object) [
            'externalid' => 'fractional-assign',
            'name' => 'Задание с дробным максимумом',
            'maxgrade' => 37.5,
        ];

        $this->expectException(UnexpectedValueException::class);
        (new assign_factory())->create($course, $sectionnum, $item);
    }

    public function test_registry_returns_injected_factories_for_supported_modules(): void {
        $this->resetAfterTest();
        $assign = new stub_activity_factory();
        $quiz = new stub_activity_factory();
        $workshop = new stub_activity_factory();
        $registry = new factory_registry($assign, $quiz, $workshop);

        $this->assertSame($assign, $registry->get(target_module::ASSIGN));
        $this->assertSame($quiz, $registry->get(target_module::QUIZ));
        $this->assertSame($workshop, $registry->get(target_module::WORKSHOP));
    }

    public function test_registry_rejects_unsupported_module(): void {
        $this->resetAfterTest();

        $this->expectException(invalid_parameter_exception::class);
        (new factory_registry())->get('lesson');
    }
}
