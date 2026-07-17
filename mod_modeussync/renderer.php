<?php

defined('MOODLE_INTERNAL') || die();

/** Renders the Modeus queue page. */
final class mod_modeussync_renderer extends plugin_renderer_base {

    public function render_queue_page(\mod_modeussync\output\queue_page $page): string {
        return $this->render_from_template('mod_modeussync/queue_page', $page->export_for_template($this));
    }
}
