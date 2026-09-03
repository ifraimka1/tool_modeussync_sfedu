<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

$help = <<<'HELP'
Safely deletes courses from category 113 whose full names contain one of the
configured teaching periods. Without --execute, the script only lists matches.

Options:
    -h, --help     Show this help.
    --execute      Delete the listed courses through the Moodle course API.

Examples:
    php admin/tool/modeussync/cli/delete_courses_by_period.php
    php admin/tool/modeussync/cli/delete_courses_by_period.php --execute
HELP;

[$options, $unrecognized] = cli_get_params(
    ['help' => false, 'execute' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

if ($options['help']) {
    cli_writeln($help);
    exit(0);
}

$categoryid = 113;
$periods = [
    '(01.09.2025-07.02.2026)',
    '(08.02.2026-30.07.2026)',
];

// Fail before selecting or deleting anything if the configured category is absent.
core_course_category::get($categoryid, MUST_EXIST, true);

$formatvalue = static function(string $value): string {
    return str_replace(["\r", "\n"], ' ', $value);
};
$formatcourse = static function(stdClass $course) use ($formatvalue): string {
    return 'id=' . (int) $course->id .
        ' | shortname=' . $formatvalue((string) $course->shortname) .
        ' | fullname=' . $formatvalue((string) $course->fullname);
};
$coursematches = static function(stdClass $course) use ($categoryid, $periods): bool {
    if ((int) $course->category !== $categoryid) {
        return false;
    }

    foreach ($periods as $period) {
        if (strpos($course->fullname, $period) !== false) {
            return true;
        }
    }

    return false;
};

$conditions = [];
$params = ['categoryid' => $categoryid];
foreach ($periods as $index => $period) {
    $paramname = 'period' . $index;
    $conditions[] = $DB->sql_like('fullname', ':' . $paramname, false);
    $params[$paramname] = '%' . $DB->sql_like_escape($period) . '%';
}

$sql = "SELECT id, category, shortname, fullname
          FROM {course}
         WHERE category = :categoryid
           AND (" . implode(' OR ', $conditions) . ")
      ORDER BY id ASC";
$candidates = $DB->get_records_sql($sql, $params);

cli_writeln('Category: ' . $categoryid);
cli_writeln('Matching courses: ' . count($candidates));

foreach ($candidates as $course) {
    cli_writeln('MATCH | ' . $formatcourse($course));
}

if (empty($candidates)) {
    cli_writeln('No matching courses found. Nothing was deleted.');
    exit(0);
}

if (!$options['execute']) {
    cli_writeln('Preview only. Nothing was deleted. Run again with --execute to delete these courses.');
    exit(0);
}

cli_writeln('Execution enabled. Revalidating and deleting ' . count($candidates) . ' course(s).');
$deleted = 0;
$skipped = 0;
$failed = 0;

foreach ($candidates as $candidate) {
    $course = $DB->get_record(
        'course',
        ['id' => $candidate->id],
        'id, category, shortname, fullname'
    );

    if ($course === false || !$coursematches($course)) {
        $skipped++;
        cli_writeln('SKIPPED | ' . $formatcourse($candidate) . ' | no longer matches');
        continue;
    }

    try {
        if (!delete_course((int) $course->id, false)) {
            throw new RuntimeException("Moodle API could not delete course {$course->id}");
        }

        $deleted++;
        cli_writeln('DELETED | ' . $formatcourse($course));
    } catch (Throwable $exception) {
        $failed++;
        cli_writeln(
            'FAILED | ' . $formatcourse($course) .
            ' | ' . get_class($exception) . ': ' . $formatvalue($exception->getMessage())
        );
    }
}

cli_writeln(
    'Finished: deleted=' . $deleted .
    ', skipped=' . $skipped .
    ', failed=' . $failed
);

exit($failed === 0 ? 0 : 1);
