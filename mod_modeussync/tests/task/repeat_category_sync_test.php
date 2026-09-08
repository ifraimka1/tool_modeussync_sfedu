<?php

defined('MOODLE_INTERNAL') || die();

use mod_modeussync\local\activity\assign_factory;
use mod_modeussync\local\activity\creation_service;
use mod_modeussync\local\activity\section_manager;
use mod_modeussync\local\global_sync\course_runner;
use mod_modeussync\local\global_sync\course_selector;
use mod_modeussync\task\repeat_category_sync;
use tool_modeussync\local\queue\queue_repository;
use tool_modeussync\local\queue\target_module;

/** SyncService double for the global adhoc task boundary. */
final class task_global_sync_fake_service extends \tool_modeussync\service\SyncService {
    /** @var array */
    public $payloads = [];

    public function send_sync_courses(array $courses): array {
        $this->payloads[] = $courses;
        return [];
    }
}

/** Test task with explicit dependency injection. */
final class testable_repeat_category_sync extends repeat_category_sync {
    /** @var course_selector */
    public $selector;

    /** @var course_runner */
    public $runner;

    protected function create_selector(): course_selector {
        return $this->selector;
    }

    protected function create_runner(): course_runner {
        return $this->runner;
    }
}

/** Tests scope transfer and validation at the adhoc-task boundary. */
final class repeat_category_sync_test extends advanced_testcase {

    public function test_executes_direct_and_recursive_scopes_with_separate_course_requests(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category(['name' => 'Parent']);
        $child = $generator->create_category(['name' => 'Child', 'parent' => $parent->id]);
        $parentcourse = $this->create_ready_course(
            (int) $parent->id,
            'parent-code',
            'parent-modeus',
            'parent-assignment'
        );
        $childcourse = $this->create_ready_course(
            (int) $child->id,
            'child-code',
            'child-modeus',
            'child-assignment'
        );

        $syncservice = new task_global_sync_fake_service();
        $directtask = $this->task($syncservice);
        $directtask->set_custom_data((object) [
            'categoryid' => (int) $parent->id,
            'includesubcategories' => false,
        ]);
        $directtask->execute();
        $this->assertSame([
            [['id_modeus' => 'parent-modeus', 'id_lms' => 'parent-code']],
        ], $syncservice->payloads);

        $syncservice->payloads = [];
        $recursivetask = $this->task($syncservice);
        $recursivetask->set_custom_data((object) [
            'categoryid' => (int) $parent->id,
            'includesubcategories' => true,
        ]);
        $recursivetask->execute();

        $expected = [
            $parentcourse->id => [['id_modeus' => 'parent-modeus', 'id_lms' => 'parent-code']],
            $childcourse->id => [['id_modeus' => 'child-modeus', 'id_lms' => 'child-code']],
        ];
        ksort($expected);
        $this->assertSame(
            array_values($expected),
            $syncservice->payloads
        );
    }

    /**
     * @dataProvider malformed_custom_data_provider
     * @param mixed $data Invalid task custom data.
     */
    public function test_rejects_malformed_custom_data($data): void {
        $this->resetAfterTest();
        $task = new repeat_category_sync();
        $task->set_custom_data($data);

        $this->expectException(coding_exception::class);
        $task->execute();
    }

    /**
     * @return array[] Invalid custom data cases.
     */
    public static function malformed_custom_data_provider(): array {
        return [
            'missing category' => [(object) ['includesubcategories' => false]],
            'zero category' => [(object) [
                'categoryid' => 0,
                'includesubcategories' => false,
            ]],
            'negative category' => [(object) [
                'categoryid' => -1,
                'includesubcategories' => false,
            ]],
            'missing recursion flag' => [(object) ['categoryid' => 1]],
            'integer recursion flag zero' => [(object) [
                'categoryid' => 1,
                'includesubcategories' => 0,
            ]],
            'integer recursion flag one' => [(object) [
                'categoryid' => 1,
                'includesubcategories' => 1,
            ]],
            'extra property' => [(object) [
                'categoryid' => 1,
                'includesubcategories' => false,
                'unexpected' => true,
            ]],
        ];
    }

    private function task(task_global_sync_fake_service $syncservice): testable_repeat_category_sync {
        $task = new testable_repeat_category_sync();
        $task->selector = new course_selector();
        $task->runner = new course_runner(new creation_service(null, null, null, $syncservice));
        return $task;
    }

    private function create_ready_course(
        int $categoryid,
        string $idnumber,
        string $idmodeus,
        string $externalid
    ): stdClass {
        $course = $this->getDataGenerator()->create_course([
            'category' => $categoryid,
            'idnumber' => $idnumber,
        ]);
        $this->getDataGenerator()->create_module('modeussync', ['course' => $course->id]);
        $repository = new queue_repository();
        $queue = $repository->upsert_course_queue($course->id, $idmodeus);
        [$item] = $repository->upsert_item($queue->id, [
            'id' => $externalid,
            'name' => 'Assignment ' . $externalid,
            'grade' => 25,
        ]);
        $cmid = (new assign_factory())->create(
            $course,
            (new section_manager())->get_or_create($course->id),
            $item
        );
        $repository->mark_item_created($item->id, $cmid, 2, target_module::ASSIGN);

        return $course;
    }
}
