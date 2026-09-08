<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\form\global_sync_form;

/** Tests the administrator-facing global repeat-sync form. */
final class global_sync_form_test extends advanced_testcase {

    public function test_renders_full_category_path_checkbox_and_primary_submit(): void {
        global $CFG, $PAGE;

        require_once($CFG->libdir . '/formslib.php');
        $this->resetAfterTest();
        $parent = $this->getDataGenerator()->create_category(['name' => 'Global Parent']);
        $this->getDataGenerator()->create_category([
            'name' => 'Global Child',
            'parent' => $parent->id,
        ]);
        $PAGE->set_context(context_system::instance());
        $form = new global_sync_form(new moodle_url('/mod/modeussync/global_sync.php'));

        ob_start();
        $form->display();
        $html = ob_get_clean();

        $this->assertSame(1, substr_count($html, 'name="categoryid"'));
        $this->assertStringContainsString('Global Parent / Global Child', $html);
        $this->assertStringContainsString('name="includesubcategories"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/name="includesubcategories"[^>]*checked/i',
            $html
        );
        $this->assertStringContainsString('btn-primary', $html);
        $this->assertStringContainsString(get_string('globalsyncsubmit', 'mod_modeussync'), $html);
    }

    public function test_rejects_missing_category(): void {
        global $CFG, $PAGE;

        require_once($CFG->libdir . '/formslib.php');
        $this->resetAfterTest();
        $PAGE->set_context(context_system::instance());
        $form = new global_sync_form(new moodle_url('/mod/modeussync/global_sync.php'));

        $errors = $form->validation([
            'categoryid' => PHP_INT_MAX,
            'includesubcategories' => 0,
        ], []);

        $this->assertSame(
            get_string('globalsyncinvalidcategory', 'mod_modeussync'),
            $errors['categoryid']
        );
    }
}
