<?php

namespace mod_modeussync\local\global_sync;

use mod_modeussync\local\activity\creation_service;

defined('MOODLE_INTERNAL') || die();

/** Runs the existing repeat-sync operation independently for each selected course. */
final class course_runner {
    /** @var creation_service */
    private $service;

    public function __construct(?creation_service $service = null) {
        $this->service = $service ?? new creation_service();
    }

    /**
     * @param array $courseids Moodle course ids.
     * @return \stdClass Counters: selected, succeeded, failed and skipped.
     */
    public function run(array $courseids): \stdClass {
        $normalized = [];
        foreach ($courseids as $courseid) {
            $courseid = (int) $courseid;
            if ($courseid > 0) {
                $normalized[$courseid] = $courseid;
            }
        }

        $result = (object) [
            'selected' => count($normalized),
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($normalized as $courseid) {
            try {
                $courseresult = $this->service->repeat_sync($courseid);
                if ($courseresult->syncsucceeded) {
                    $result->succeeded++;
                    mtrace('[mod_modeussync global sync] course ' . $courseid . ': succeeded');
                } else {
                    $result->failed++;
                    mtrace('[mod_modeussync global sync] course ' . $courseid . ': failed');
                }
            } catch (\moodle_exception $exception) {
                if ($exception->errorcode === 'nothingtorepeatlink') {
                    $result->skipped++;
                    mtrace('[mod_modeussync global sync] course ' . $courseid . ': skipped');
                    continue;
                }
                $result->failed++;
                mtrace(
                    '[mod_modeussync global sync] course ' . $courseid . ': ' .
                    get_class($exception)
                );
            } catch (\Throwable $exception) {
                $result->failed++;
                mtrace(
                    '[mod_modeussync global sync] course ' . $courseid . ': ' .
                    get_class($exception)
                );
            }
        }

        return $result;
    }
}
