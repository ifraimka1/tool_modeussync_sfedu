# mod_modeussync Manual Activity Creation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Перенести автоматическое создание заданий Modeus из `pull_courses` в единственный системный `mod_modeussync`, через который преподаватель вручную создаёт `assign` или пустой `quiz` с `maxgrade = courseData.grade`.

**Architecture:** `tool_modeussync` владеет интеграцией и персистентной очередью `results[*].courseData`; он сохраняет задания и публикует Moodle event. `mod_modeussync` зависит от `tool_modeussync`, обеспечивает один activity-экземпляр на курс, создаёт выбранные Moodle activities и вызывает существующий `POST /sync` после успешного создания всей очереди. Созданные `assign` и `quiz` затем передаются штатным потоком `push_courses -> LmsAdapter`; технический `mod_modeussync` из этого потока исключается.

**Tech Stack:** PHP 7.4+/8.x, Moodle 4.1+ API (`$plugin->requires = 2022112800`), Moodle XMLDB/DML, Events API, Lock API, Activity module API, PHPUnit `advanced_testcase`, Mustache renderer.

## Global Constraints

- Реализация соответствует [ADR-0001](../../adr/0001-mod-modeussync-manual-activity-creation.md).
- SyncService и контракты `POST /new-course` и `POST /sync` не изменяются.
- `POST /sync` получает только `id_modeus` и `course.idnumber` в поле `id_lms`.
- Поддерживаемые создаваемые типы первой версии: только `assign` и `quiz`; default — `assign`.
- `quiz` создаётся пустым, с одной попыткой, методом «Лучшая оценка» и `grade = courseData.grade`.
- `assign` создаётся с `grade = courseData.grade`.
- `course_modules.idnumber` созданного элемента равен `courseData.id`.
- На курсе может существовать только один `mod_modeussync`.
- Ручное добавление второго или первого экземпляра через стандартную форму блокируется.
- Повторное получение одинакового `courseData` не создаёт дубликаты.
- Частичный успех не откатывается; `/sync` вызывается только после состояния `created` у всех элементов.
- Студент не имеет доступа к странице или POST-операции.
- API key не выводится в HTML или журналы.
- `mod_modeussync` исключается из `push_courses` и списка `moduleTypes`.
- Browser/Behat-проверки не входят в план; проверки выполняются PHPUnit и CLI.
- Git-команды и commit-шаги намеренно отсутствуют по инструкции пользователя.
- Команды PHPUnit выполняются из корня установленного Moodle, где репозиторные каталоги подключены как `admin/tool/modeussync` и `mod/modeussync`.

---

## Target File Map

### `tool_modeussync`

- `db/install.xml` — две таблицы очереди для новой установки.
- `db/upgrade.php` — создание таблиц при обновлении с `2025060300`.
- `classes/local/queue/course_status.php` — допустимые состояния очереди курса.
- `classes/local/queue/item_status.php` — допустимые состояния элемента.
- `classes/local/queue/target_module.php` — allow-list `assign|quiz`.
- `classes/local/queue/queue_repository.php` — единственная DML-граница очереди.
- `classes/local/queue/sync_response_ingestor.php` — валидация и идемпотентный upsert `results[*].courseData`.
- `classes/event/assignments_queued.php` — событие для создания/восстановления `mod_modeussync`.
- `classes/service/logger/logger_interface.php` — абстракция логирования SyncService.
- `classes/service/logger/cron_logger.php` — логирование через `mtrace`.
- `classes/service/logger/web_logger.php` — server-side логирование без HTML-вывода.
- `classes/service/SyncService.php` — внедряемый logger без изменения HTTP-контрактов.
- `classes/task/pull_courses.php` — сохранение очереди вместо создания `mod_assign` и `/sync`.
- `classes/task/push_courses.php` — исключение `modeussync` из экспорта.
- `classes/courses_consts.php` — технические типы, не экспортируемые в LmsAdapter.
- `version.php` — версия `2026071600`.
- `lang/en/tool_modeussync.php`, `lang/ru/tool_modeussync.php` — строки событий и ошибок.
- `tests/local/queue/queue_repository_test.php`
- `tests/local/queue/sync_response_ingestor_test.php`
- `tests/task/pull_courses_response_test.php`
- `tests/task/push_courses_test.php`
- `tests/service/sync_service_logger_test.php`

### `mod_modeussync`

- `version.php` — component и dependency на `tool_modeussync`.
- `db/install.xml` — таблица экземпляров с unique index по `course`.
- `db/access.php` — `addinstance`, `view`, `manage`.
- `db/events.php` — observer события `tool_modeussync`.
- `db/tasks.php` — периодическое восстановление отсутствующих экземпляров.
- `lang/en/modeussync.php`, `lang/ru/modeussync.php` — UI и ошибки.
- `lib.php` — стандартные callbacks activity и системные guards.
- `mod_form.php` — запрет ручного добавления, разрешение редактирования.
- `view.php` — GET/POST controller.
- `index.php` — список экземпляров курса или redirect.
- `renderer.php` — renderer страницы очереди.
- `templates/queue_page.mustache` — вертикальный список заданий и dropdown.
- `classes/local/instance_guard.php` — request-local разрешение системного add/delete.
- `classes/local/instance_manager.php` — идемпотентное создание одного экземпляра.
- `classes/observer.php` — реакция на `assignments_queued`.
- `classes/task/ensure_instances.php` — восстановление activity по сохранённым очередям.
- `classes/local/activity/section_manager.php` — секция «Задания из Modeus».
- `classes/local/activity/activity_factory_interface.php`
- `classes/local/activity/assign_factory.php`
- `classes/local/activity/quiz_factory.php`
- `classes/local/activity/factory_registry.php`
- `classes/local/activity/creation_service.php` — lock, reconciliation, создание, состояния, `/sync`.
- `classes/local/access.php` — централизованные capability-проверки страницы и POST.
- `classes/output/queue_page.php` — templatable data.
- `classes/event/creation_started.php`
- `classes/event/activity_created.php`
- `classes/event/activity_creation_failed.php`
- `classes/event/queue_completed.php`
- `classes/event/sync_succeeded.php`
- `classes/event/sync_failed.php`
- `tests/generator/lib.php`
- `tests/local/instance_manager_test.php`
- `tests/local/activity/assign_factory_test.php`
- `tests/local/activity/quiz_factory_test.php`
- `tests/local/activity/creation_service_test.php`
- `tests/view_access_test.php`

---

### Task 1: Add persistent queue schema and domain constants

**Files:**

- Create: `tool_modeussync/db/install.xml`
- Create: `tool_modeussync/db/upgrade.php`
- Create: `tool_modeussync/classes/local/queue/course_status.php`
- Create: `tool_modeussync/classes/local/queue/item_status.php`
- Create: `tool_modeussync/classes/local/queue/target_module.php`
- Modify: `tool_modeussync/version.php`
- Test: `tool_modeussync/tests/local/queue/queue_repository_test.php`

**Interfaces:**

- Produces:
  - `course_status::{PENDING, PROCESSING, AWAITING_SYNC, SYNCED, SYNC_FAILED}`
  - `item_status::{PENDING, PROCESSING, CREATED, FAILED}`
  - `target_module::{ASSIGN, QUIZ}`
  - `target_module::is_supported(string $value): bool`

- [ ] **Step 1: Write a failing schema/domain test**

```php
<?php
namespace tool_modeussync\local\queue;

defined('MOODLE_INTERNAL') || die();

final class queue_repository_test extends \advanced_testcase {
    public function test_queue_tables_and_unique_indexes_exist(): void {
        global $DB;
        $this->resetAfterTest();

        $dbman = $DB->get_manager();
        $this->assertTrue($dbman->table_exists(new \xmldb_table('tool_modeussync_course_queue')));
        $this->assertTrue($dbman->table_exists(new \xmldb_table('tool_modeussync_queue_items')));

        $queue = (object)[
            'courseid' => $this->getDataGenerator()->create_course()->id,
            'idmodeus' => 'modeus-course-1',
            'status' => course_status::PENDING,
            'lasterror' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'timesynced' => null,
        ];
        $DB->insert_record('tool_modeussync_course_queue', $queue);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('tool_modeussync_course_queue', $queue);
    }

    public function test_target_module_allow_list(): void {
        $this->assertTrue(target_module::is_supported('assign'));
        $this->assertTrue(target_module::is_supported('quiz'));
        $this->assertFalse(target_module::is_supported('lesson'));
        $this->assertSame('assign', target_module::DEFAULT);
    }
}
```

- [ ] **Step 2: Run the test and verify the new schema/classes are missing**

Run:

```text
vendor/bin/phpunit admin/tool/modeussync/tests/local/queue/queue_repository_test.php
```

Expected: FAIL because the tables and domain classes do not exist.

- [ ] **Step 3: Add exact XMLDB tables**

Create `tool_modeussync/db/install.xml` with these fields and indexes:

```xml
<?xml version="1.0" encoding="UTF-8" ?>
<XMLDB PATH="admin/tool/modeussync/db" VERSION="20260716"
       COMMENT="XMLDB file for tool_modeussync"
       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
       xsi:noNamespaceSchemaLocation="../../../../lib/xmldb/xmldb.xsd">
  <TABLES>
    <TABLE NAME="tool_modeussync_course_queue" COMMENT="Course-level Modeus assignment creation queue">
      <FIELDS>
        <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="courseid" TYPE="int" LENGTH="10" NOTNULL="true"/>
        <FIELD NAME="idmodeus" TYPE="char" LENGTH="255" NOTNULL="true"/>
        <FIELD NAME="status" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="pending"/>
        <FIELD NAME="lasterror" TYPE="text" NOTNULL="false"/>
        <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true"/>
        <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true"/>
        <FIELD NAME="timesynced" TYPE="int" LENGTH="10" NOTNULL="false"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
        <KEY NAME="course_fk" TYPE="foreign" FIELDS="courseid" REFTABLE="course" REFFIELDS="id"/>
      </KEYS>
      <INDEXES>
        <INDEX NAME="course_uix" UNIQUE="true" FIELDS="courseid"/>
        <INDEX NAME="status_ix" UNIQUE="false" FIELDS="status"/>
      </INDEXES>
    </TABLE>
    <TABLE NAME="tool_modeussync_queue_items" COMMENT="Items received in results courseData">
      <FIELDS>
        <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="queueid" TYPE="int" LENGTH="10" NOTNULL="true"/>
        <FIELD NAME="externalid" TYPE="char" LENGTH="255" NOTNULL="true"/>
        <FIELD NAME="name" TYPE="char" LENGTH="255" NOTNULL="true"/>
        <FIELD NAME="maxgrade" TYPE="number" LENGTH="10" DECIMALS="5" NOTNULL="true"/>
        <FIELD NAME="targetmodule" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="assign"/>
        <FIELD NAME="status" TYPE="char" LENGTH="20" NOTNULL="true" DEFAULT="pending"/>
        <FIELD NAME="coursemoduleid" TYPE="int" LENGTH="10" NOTNULL="false"/>
        <FIELD NAME="createdby" TYPE="int" LENGTH="10" NOTNULL="false"/>
        <FIELD NAME="lasterror" TYPE="text" NOTNULL="false"/>
        <FIELD NAME="payloadjson" TYPE="text" NOTNULL="false"/>
        <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true"/>
        <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
        <KEY NAME="queue_fk" TYPE="foreign" FIELDS="queueid"
             REFTABLE="tool_modeussync_course_queue" REFFIELDS="id"/>
        <KEY NAME="cm_fk" TYPE="foreign" FIELDS="coursemoduleid"
             REFTABLE="course_modules" REFFIELDS="id"/>
        <KEY NAME="user_fk" TYPE="foreign" FIELDS="createdby" REFTABLE="user" REFFIELDS="id"/>
      </KEYS>
      <INDEXES>
        <INDEX NAME="queueexternal_uix" UNIQUE="true" FIELDS="queueid, externalid"/>
        <INDEX NAME="queuestatus_ix" UNIQUE="false" FIELDS="queueid, status"/>
      </INDEXES>
    </TABLE>
  </TABLES>
</XMLDB>
```

Add the same table definitions through XMLDB objects in `db/upgrade.php` under:

```php
if ($oldversion < 2026071600) {
    // Create tool_modeussync_course_queue when missing.
    // Create tool_modeussync_queue_items when missing.
    upgrade_plugin_savepoint(true, 2026071600, 'tool', 'modeussync');
}
```

The upgrade implementation must use `xmldb_table`, `xmldb_field`, `xmldb_key`, and `xmldb_index`, and check `table_exists()` before `create_table()`.

- [ ] **Step 4: Add immutable domain constants**

Implement each class as `final` with a private constructor. Example:

```php
final class target_module {
    public const ASSIGN = 'assign';
    public const QUIZ = 'quiz';
    public const DEFAULT = self::ASSIGN;

    private function __construct() {
    }

    public static function is_supported(string $value): bool {
        return in_array($value, [self::ASSIGN, self::QUIZ], true);
    }
}
```

Use equivalent constant-only implementations for course and item statuses.

- [ ] **Step 5: Set the tool version**

```php
$plugin->version = 2026071600;
$plugin->requires = 2022112800;
$plugin->component = 'tool_modeussync';
```

- [ ] **Step 6: Run schema upgrade and tests**

Run:

```text
php admin/cli/upgrade.php --non-interactive
vendor/bin/phpunit admin/tool/modeussync/tests/local/queue/queue_repository_test.php
```

Expected: upgrade exits successfully; both tests PASS.

---

### Task 2: Implement the queue repository, ingest service, and queue event

**Files:**

- Create: `tool_modeussync/classes/local/queue/queue_repository.php`
- Create: `tool_modeussync/classes/local/queue/sync_response_ingestor.php`
- Create: `tool_modeussync/classes/event/assignments_queued.php`
- Test: `tool_modeussync/tests/local/queue/sync_response_ingestor_test.php`

**Interfaces:**

- Produces:

```php
queue_repository::get_course_queue(int $courseid): ?\stdClass
queue_repository::get_items(int $queueid): array
queue_repository::get_item(int $itemid): \stdClass
queue_repository::upsert_course_queue(int $courseid, string $idmodeus): \stdClass
queue_repository::upsert_item(int $queueid, array $item): array
queue_repository::course_has_items(int $courseid): bool
queue_repository::set_course_status(int $queueid, string $status, ?string $error = null): void
queue_repository::mark_course_synced(int $queueid, int $timesynced): void
queue_repository::set_item_status(int $itemid, string $status, ?string $error = null): void
queue_repository::mark_item_created(int $itemid, int $cmid, int $createdby,
    string $actualmodule): void
queue_repository::save_target_modules(int $queueid, array $selections): void
queue_repository::get_course_ids_with_items(int $limit = 100): array

sync_response_ingestor::ingest(array $response): array
```

`ingest()` returns queue records changed by the response. It throws `UnexpectedValueException` for invalid course-level results and stores invalid individual items as `failed`.

- [ ] **Step 1: Write failing ingest tests**

Cover these exact cases:

```php
public function test_ingest_creates_queue_and_items_with_assign_default(): void;
public function test_repeated_response_does_not_duplicate_items(): void;
public function test_pending_item_updates_name_and_grade_but_preserves_quiz_selection(): void;
public function test_created_item_is_not_reopened_by_identical_response(): void;
public function test_new_item_reopens_synced_queue(): void;
public function test_missing_results_throws(): void;
public function test_invalid_item_is_stored_failed_and_blocks_sync(): void;
public function test_event_is_triggered_only_when_course_has_course_data(): void;
```

Use this response fixture:

```php
$response = [
    'results' => [[
        'success' => true,
        'id_lms' => $course->id,
        'id_modeus' => 'modeus-course-1',
        'courseData' => [
            ['id' => 'meeting-1', 'name' => 'Контрольная работа', 'grade' => 25],
            ['id' => 'meeting-2', 'name' => 'Итоговый тест', 'grade' => 75.5],
        ],
    ]],
];
```

For the event test, use `\core\event\base::set_event_sink()` and assert:

```php
$events = $sink->get_events();
$this->assertCount(1, $events);
$this->assertInstanceOf(\tool_modeussync\event\assignments_queued::class, $events[0]);
$this->assertSame($course->id, $events[0]->courseid);
```

- [ ] **Step 2: Run tests and verify failure**

Run:

```text
vendor/bin/phpunit admin/tool/modeussync/tests/local/queue/sync_response_ingestor_test.php
```

Expected: FAIL because repository, ingestor, and event do not exist.

- [ ] **Step 3: Implement `queue_repository` as the only queue DML boundary**

Key rules:

```php
public function upsert_item(int $queueid, array $item): array {
    global $DB;

    $externalid = trim((string)($item['id'] ?? ''));
    $name = trim((string)($item['name'] ?? ''));
    $grade = $item['grade'] ?? null;
    $payloadjson = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $storageexternalid = $externalid === ''
        ? 'invalid:' . hash('sha256', (string)$payloadjson)
        : $externalid;

    $existing = $DB->get_record('tool_modeussync_queue_items', [
        'queueid' => $queueid,
        'externalid' => $storageexternalid,
    ]);

    if (!$existing) {
        $valid = $externalid !== '' && $name !== '' && is_numeric($grade) && (float)$grade > 0;
        $record = (object)[
            'queueid' => $queueid,
            'externalid' => $storageexternalid,
            'name' => $name === '' ? get_string('invalidassignmentname', 'tool_modeussync') : $name,
            'maxgrade' => is_numeric($grade) ? (float)$grade : 0,
            'targetmodule' => target_module::DEFAULT,
            'status' => $valid ? item_status::PENDING : item_status::FAILED,
            'coursemoduleid' => null,
            'createdby' => null,
            'lasterror' => $valid ? null : get_string('invalidassignmentpayload', 'tool_modeussync'),
            'payloadjson' => $payloadjson,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $record->id = $DB->insert_record('tool_modeussync_queue_items', $record);
        return [$record, true];
    }

    $changed = false;
    if ($existing->status !== item_status::CREATED) {
        $newname = $name === '' ? $existing->name : $name;
        $newgrade = is_numeric($grade) && (float)$grade > 0 ? (float)$grade : $existing->maxgrade;
        $changed = $newname !== $existing->name
            || (float)$newgrade !== (float)$existing->maxgrade
            || $payloadjson !== $existing->payloadjson;
        $existing->name = $newname;
        $existing->maxgrade = $newgrade;
    }
    $existing->payloadjson = $payloadjson;
    $existing->timemodified = time();
    $DB->update_record('tool_modeussync_queue_items', $existing);

    return [$existing, $changed];
}
```

Do not overwrite `targetmodule` for existing records. `save_target_modules()` must:

- accept only item IDs belonging to the given queue;
- reject values outside `assign|quiz`;
- ignore records already `created`;
- update `pending` and `failed` rows.

- [ ] **Step 4: Implement ingest transaction and event**

`sync_response_ingestor::ingest()`:

1. validates top-level `results`;
2. for each result validates `success`, `id_lms`, `id_modeus`;
3. verifies Moodle course exists;
4. ignores empty `courseData` without creating an activity queue;
5. opens one delegated transaction per course result;
6. upserts course queue and items;
7. sets queue to:
   - `pending` when at least one item is not `created`;
   - existing `synced` when response is identical and all items remain created;
8. commits;
9. triggers `assignments_queued` after commit.

Event construction:

```php
$event = assignments_queued::create([
    'objectid' => $queue->id,
    'context' => \context_course::instance($courseid),
    'other' => [
        'idmodeus' => $idmodeus,
        'itemcount' => count($courseData),
    ],
]);
$event->trigger();
```

- [ ] **Step 5: Run tests**

Run:

```text
vendor/bin/phpunit admin/tool/modeussync/tests/local/queue/sync_response_ingestor_test.php
```

Expected: all ingest, idempotency, status, and event tests PASS.

---

### Task 3: Replace automatic assignment creation in `pull_courses`

**Files:**

- Modify: `tool_modeussync/classes/task/pull_courses.php`
- Test: `tool_modeussync/tests/task/pull_courses_response_test.php`

**Interfaces:**

- Consumes: `sync_response_ingestor::ingest(array $response): array`
- Produces: `pull_courses::process_sync_response(array $response): array` returning changed queue records for logging only.

- [ ] **Step 1: Extract a testable response-processing seam**

Change visibility of `process_sync_response()` from `private` to `protected` and add:

```php
protected function create_sync_response_ingestor(): sync_response_ingestor {
    return new sync_response_ingestor(new queue_repository());
}
```

Create a test subclass exposing the method:

```php
final class testable_pull_courses extends \tool_modeussync\task\pull_courses {
    public function process(array $response): array {
        return $this->process_sync_response($response);
    }
}
```

- [ ] **Step 2: Write failing tests**

Tests must assert:

- response `courseData` creates queue records;
- no `assign` course module is created;
- no section is created solely for assignments at this stage;
- response with `success=false` throws;
- empty `courseData` creates no queue and returns an empty array.

Core assertion:

```php
$task->process($response);
$this->assertFalse($DB->record_exists('course_modules', ['course' => $course->id]));
$this->assertCount(2, $DB->get_records('tool_modeussync_queue_items'));
```

- [ ] **Step 3: Run tests and observe old behavior**

Run:

```text
vendor/bin/phpunit admin/tool/modeussync/tests/task/pull_courses_response_test.php
```

Expected: FAIL because the current implementation creates `mod_assign` and has no queue.

- [ ] **Step 4: Replace response processing**

Implement:

```php
protected function process_sync_response(array $response): array {
    if (empty($response)) {
        throw new \UnexpectedValueException('SyncService response is empty');
    }

    $queues = $this->create_sync_response_ingestor()->ingest($response);
    mtrace('Очередей заданий сохранено или обновлено: ' . count($queues));

    return $queues;
}
```

In `do_work()`:

- keep `/new-course`;
- call `process_sync_response($syncResponse)`;
- remove the `send_sync_courses()` block;
- log that assignments await teacher action.

Delete these obsolete methods from `pull_courses`:

```text
create_modeus_assignments_from_result
filter_missing_modeus_assignment_items
format_assignment_ids_for_log
get_or_create_modeus_assignments_section
create_modeus_assignment
```

Do not change course/section/module creation from the original LmsAdapter prototype.

- [ ] **Step 5: Run focused tests**

Run:

```text
vendor/bin/phpunit admin/tool/modeussync/tests/task/pull_courses_response_test.php
vendor/bin/phpunit admin/tool/modeussync/tests/local/queue/sync_response_ingestor_test.php
```

Expected: PASS; no automatic `assign` and no `/sync` from response processing.

---

### Task 4: Make `SyncService` safe for cron and web contexts

**Files:**

- Create: `tool_modeussync/classes/service/logger/logger_interface.php`
- Create: `tool_modeussync/classes/service/logger/cron_logger.php`
- Create: `tool_modeussync/classes/service/logger/web_logger.php`
- Modify: `tool_modeussync/classes/service/SyncService.php`
- Test: `tool_modeussync/tests/service/sync_service_logger_test.php`

**Interfaces:**

```php
interface logger_interface {
    public function log(string $message): void;
}

SyncService::__construct(?logger_interface $logger = null)
SyncService::send_created_courses(array $courses): array
SyncService::send_sync_courses(array $courses): array
```

- [ ] **Step 1: Write logger tests**

Use a collecting logger:

```php
final class collecting_logger implements logger_interface {
    public array $messages = [];

    public function log(string $message): void {
        $this->messages[] = $message;
    }
}
```

Tests:

- injected logger receives URL, count, and bounded response logs;
- no message contains the configured API key;
- default logger is `cron_logger` when `CLI_SCRIPT` is true;
- `web_logger` uses `error_log()` and never calls `mtrace()`;
- existing response validation remains unchanged.

- [ ] **Step 2: Run tests and verify failure**

Run:

```text
vendor/bin/phpunit admin/tool/modeussync/tests/service/sync_service_logger_test.php
```

Expected: FAIL because constructor injection and logger classes do not exist.

- [ ] **Step 3: Replace direct `mtrace()` calls**

Constructor:

```php
public function __construct(?logger_interface $logger = null) {
    if ($logger !== null) {
        $this->logger = $logger;
        return;
    }

    $this->logger = defined('CLI_SCRIPT') && CLI_SCRIPT
        ? new cron_logger()
        : new web_logger();
}
```

Logger implementations:

```php
final class cron_logger implements logger_interface {
    public function log(string $message): void {
        mtrace($message);
    }
}

final class web_logger implements logger_interface {
    public function log(string $message): void {
        error_log('[tool_modeussync SyncService] ' . $message);
    }
}
```

Replace every direct `mtrace()` in `SyncService` with `$this->logger->log()`. Do not log HTTP headers. Keep payload and response truncation at 4096 bytes.

- [ ] **Step 4: Run SyncService tests**

Run:

```text
vendor/bin/phpunit admin/tool/modeussync/tests/service/sync_service_logger_test.php
```

Expected: PASS; public method signatures remain backward compatible.

---

### Task 5: Scaffold `mod_modeussync` with single-instance storage and capabilities

**Files:**

- Create: `mod_modeussync/version.php`
- Create: `mod_modeussync/db/install.xml`
- Create: `mod_modeussync/db/access.php`
- Create: `mod_modeussync/lang/en/modeussync.php`
- Create: `mod_modeussync/lang/ru/modeussync.php`
- Create: `mod_modeussync/lib.php`
- Create: `mod_modeussync/mod_form.php`
- Create: `mod_modeussync/classes/local/instance_guard.php`
- Create: `mod_modeussync/tests/generator/lib.php`
- Test: `mod_modeussync/tests/local/instance_manager_test.php`

**Interfaces:**

```php
instance_guard::run_system_add(callable $callback)
instance_guard::is_system_add_allowed(): bool
instance_guard::run_system_delete(callable $callback)
instance_guard::is_system_delete_allowed(): bool
```

- [ ] **Step 1: Write failing single-instance tests**

Tests:

```php
public function test_add_instance_rejects_manual_call(): void;
public function test_system_guard_allows_first_instance(): void;
public function test_unique_course_index_rejects_second_instance(): void;
public function test_update_instance_allows_same_record(): void;
public function test_editing_teacher_has_view_and_manage_but_not_addinstance(): void;
```

Use `instance_guard::run_system_add()` around the generator/internal add path.

- [ ] **Step 2: Run tests and verify missing plugin**

Run:

```text
vendor/bin/phpunit mod/modeussync/tests/local/instance_manager_test.php
```

Expected: FAIL because the activity plugin is not scaffolded.

- [ ] **Step 3: Add version and dependency**

```php
$plugin->version = 2026071600;
$plugin->requires = 2022112800;
$plugin->component = 'mod_modeussync';
$plugin->dependencies = [
    'tool_modeussync' => 2026071600,
];
```

- [ ] **Step 4: Add the activity table**

`mod_modeussync/db/install.xml` defines:

```xml
<TABLE NAME="modeussync" COMMENT="Single system Modeus assignment creator per course">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="course" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="name" TYPE="char" LENGTH="255" NOTNULL="true"/>
    <FIELD NAME="intro" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="introformat" TYPE="int" LENGTH="4" NOTNULL="true" DEFAULT="0"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="course_fk" TYPE="foreign" FIELDS="course" REFTABLE="course" REFFIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="course_uix" UNIQUE="true" FIELDS="course"/>
  </INDEXES>
</TABLE>
```

- [ ] **Step 5: Add capabilities**

`db/access.php`:

```php
$capabilities = [
    'mod/modeussync:addinstance' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [],
    ],
    'mod/modeussync:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    'mod/modeussync:manage' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
```

- [ ] **Step 6: Implement standard callbacks with guards**

`mod_modeussync_add_instance()`:

```php
function modeussync_add_instance($data, $mform = null): int {
    global $DB;

    if (!\mod_modeussync\local\instance_guard::is_system_add_allowed()) {
        throw new moodle_exception('manualcreationdisabled', 'mod_modeussync');
    }
    if ($DB->record_exists('modeussync', ['course' => $data->course])) {
        throw new moodle_exception('singleinstanceonly', 'mod_modeussync');
    }

    $data->timecreated = time();
    $data->timemodified = time();
    return $DB->insert_record('modeussync', $data);
}
```

`modeussync_update_instance()` updates only the existing row and rejects a different row for the same course.

`modeussync_delete_instance()` rejects ordinary web deletion. It permits:

- `instance_guard::is_system_delete_allowed()`;
- full course deletion detected inside the core `delete_course()` / `remove_course_contents()` stack.

This prevents teacher and ad-hoc CLI activity deletion without blocking course deletion. Plugin maintenance must use the explicit internal guard.

- [ ] **Step 7: Block the standard add form**

In `mod_form.php`, throw `manualcreationdisabled` when creating a new instance outside `instance_guard`; allow editing an existing instance.

- [ ] **Step 8: Run upgrade and tests**

Run:

```text
php admin/cli/upgrade.php --non-interactive
vendor/bin/phpunit mod/modeussync/tests/local/instance_manager_test.php
```

Expected: PASS; DB unique index and code guard both prevent a second instance.

---

### Task 6: Create and restore the single activity instance from queue events

**Files:**

- Create: `mod_modeussync/db/events.php`
- Create: `mod_modeussync/db/tasks.php`
- Create: `mod_modeussync/classes/observer.php`
- Create: `mod_modeussync/classes/local/instance_manager.php`
- Create: `mod_modeussync/classes/task/ensure_instances.php`
- Create: `mod_modeussync/classes/local/activity/section_manager.php`
- Modify: `mod_modeussync/tests/local/instance_manager_test.php`

**Interfaces:**

```php
section_manager::get_or_create(int $courseid): int
instance_manager::ensure_for_course(int $courseid): \stdClass
observer::assignments_queued(\tool_modeussync\event\assignments_queued $event): void
ensure_instances::execute(): void
```

- [ ] **Step 1: Add failing observer/manager tests**

Tests:

- event creates one `modeussync` record and one course module;
- repeated event leaves counts at one;
- existing queue without instance is restored by scheduled task;
- no queue items means no instance;
- created instance is in section «Задания из Modeus»;
- lock + unique index handle two sequential ensure calls without duplication.

Core assertion:

```php
$manager->ensure_for_course($course->id);
$manager->ensure_for_course($course->id);

$this->assertSame(1, $DB->count_records('modeussync', ['course' => $course->id]));
$moduleid = $DB->get_field('modules', 'id', ['name' => 'modeussync'], MUST_EXIST);
$this->assertSame(1, $DB->count_records('course_modules', [
    'course' => $course->id,
    'module' => $moduleid,
]));
```

- [ ] **Step 2: Run tests**

Run:

```text
vendor/bin/phpunit mod/modeussync/tests/local/instance_manager_test.php
```

Expected: FAIL because manager, observer, task, and section service do not exist.

- [ ] **Step 3: Implement section manager**

Use exact section name from ADR:

```php
private const SECTION_NAME = 'Задания из Modeus';
```

Reuse an existing section with that name; otherwise append `MAX(section) + 1`, set its name, and call `rebuild_course_cache($courseid, true)`.

- [ ] **Step 4: Implement `instance_manager`**

Algorithm:

```php
public function ensure_for_course(int $courseid): \stdClass {
    global $CFG, $DB;

    if (!$this->queues->course_has_items($courseid)) {
        throw new \coding_exception('Cannot create mod_modeussync without queue items');
    }

    $factory = \core\lock\lock_config::get_lock_factory('mod_modeussync');
    $lock = $factory->get_lock('instance:' . $courseid, 10);
    if (!$lock) {
        throw new \moodle_exception('cannotacquirelock', 'mod_modeussync');
    }

    try {
        $existing = $DB->get_record('modeussync', ['course' => $courseid]);
        if ($existing) {
            return $existing;
        }

        require_once($CFG->dirroot . '/course/modlib.php');
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $module = $DB->get_record('modules', ['name' => 'modeussync'], '*', MUST_EXIST);
        $sectionnum = $this->sections->get_or_create($courseid);

        $moduleinfo = (object)[
            'course' => $courseid,
            'module' => $module->id,
            'modulename' => 'modeussync',
            'add' => 'modeussync',
            'name' => get_string('activityname', 'mod_modeussync'),
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'section' => $sectionnum,
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'cmidnumber' => '',
            'groupmode' => 0,
            'groupingid' => 0,
            'availability' => null,
            'completion' => 0,
            'showdescription' => 0,
        ];

        return instance_guard::run_system_add(
            static fn() => add_moduleinfo($moduleinfo, $course)
        );
    } finally {
        $lock->release();
    }
}
```

Return the activity record after resolving `moduleinfo->instance`, not the raw `moduleinfo`.

- [ ] **Step 5: Register observer and restoration task**

`db/events.php`:

```php
$observers = [[
    'eventname' => '\tool_modeussync\event\assignments_queued',
    'callback' => '\mod_modeussync\observer::assignments_queued',
]];
```

`db/tasks.php`: run `\mod_modeussync\task\ensure_instances` every 5 minutes.

The task processes at most 100 course IDs from `queue_repository::get_course_ids_with_items(100)` and calls `ensure_for_course()`. One course failure is logged and does not stop the remaining courses.

- [ ] **Step 6: Run tests**

Run:

```text
vendor/bin/phpunit mod/modeussync/tests/local/instance_manager_test.php
php admin/cli/scheduled_task.php --execute='\mod_modeussync\task\ensure_instances'
```

Expected: PHPUnit PASS; scheduled task exits successfully and creates no duplicates.

---

### Task 7: Implement `assign` and empty `quiz` factories with max grades

**Files:**

- Create: `mod_modeussync/classes/local/activity/activity_factory_interface.php`
- Create: `mod_modeussync/classes/local/activity/assign_factory.php`
- Create: `mod_modeussync/classes/local/activity/quiz_factory.php`
- Create: `mod_modeussync/classes/local/activity/factory_registry.php`
- Test: `mod_modeussync/tests/local/activity/assign_factory_test.php`
- Test: `mod_modeussync/tests/local/activity/quiz_factory_test.php`

**Interfaces:**

```php
interface activity_factory_interface {
    public function create(\stdClass $course, int $sectionnum, \stdClass $item): int;
}

factory_registry::get(string $modulename): activity_factory_interface
```

Return value is Moodle `course_modules.id`.

- [ ] **Step 1: Write failing `assign` test**

Create an item:

```php
$item = (object)[
    'externalid' => 'meeting-assign-1',
    'name' => 'Практическая работа',
    'maxgrade' => 37.5,
];
```

After factory creation assert:

```php
$cm = get_coursemodule_from_id('assign', $cmid, $course->id, false, MUST_EXIST);
$assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
$gradeitem = $DB->get_record('grade_items', [
    'courseid' => $course->id,
    'itemmodule' => 'assign',
    'iteminstance' => $assign->id,
], '*', MUST_EXIST);

$this->assertSame('meeting-assign-1', $cm->idnumber);
$this->assertSame('Практическая работа', $assign->name);
$this->assertEquals(37.5, (float)$gradeitem->grademax);
```

- [ ] **Step 2: Write failing `quiz` test**

Use `maxgrade = 62`. Assert:

```php
$cm = get_coursemodule_from_id('quiz', $cmid, $course->id, false, MUST_EXIST);
$quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
$gradeitem = $DB->get_record('grade_items', [
    'courseid' => $course->id,
    'itemmodule' => 'quiz',
    'iteminstance' => $quiz->id,
], '*', MUST_EXIST);

$this->assertSame('meeting-quiz-1', $cm->idnumber);
$this->assertEquals(62.0, (float)$quiz->grade);
$this->assertEquals(62.0, (float)$gradeitem->grademax);
$this->assertEquals(0.0, (float)$quiz->sumgrades);
$this->assertSame(1, (int)$quiz->attempts);
$this->assertSame(QUIZ_GRADEHIGHEST, (int)$quiz->grademethod);
```

- [ ] **Step 3: Run tests and verify missing factories**

Run:

```text
vendor/bin/phpunit mod/modeussync/tests/local/activity/assign_factory_test.php
vendor/bin/phpunit mod/modeussync/tests/local/activity/quiz_factory_test.php
```

Expected: FAIL because factory classes do not exist.

- [ ] **Step 4: Implement shared moduleinfo fields**

Each factory builds its own complete `stdClass`; do not create a generic all-module options bag.

Common fields:

```php
$moduleinfo->course = $course->id;
$moduleinfo->module = $module->id;
$moduleinfo->modulename = $modulename;
$moduleinfo->add = $modulename;
$moduleinfo->name = $item->name;
$moduleinfo->intro = '';
$moduleinfo->introformat = FORMAT_HTML;
$moduleinfo->section = $sectionnum;
$moduleinfo->visible = 1;
$moduleinfo->visibleoncoursepage = 1;
$moduleinfo->cmidnumber = $item->externalid;
$moduleinfo->idnumber = $item->externalid;
$moduleinfo->groupmode = 0;
$moduleinfo->groupingid = 0;
$moduleinfo->availability = null;
$moduleinfo->completion = 0;
$moduleinfo->showdescription = 0;
```

- [ ] **Step 5: Implement `assign_factory`**

Preserve current behavior:

```php
$moduleinfo->grade = (float)$item->maxgrade;
$moduleinfo->gradecat = 0;
$moduleinfo->allowsubmissionsfromdate = 0;
$moduleinfo->duedate = 0;
$moduleinfo->cutoffdate = 0;
$moduleinfo->gradingduedate = 0;
$moduleinfo->assignsubmission_onlinetext_enabled = 0;
$moduleinfo->assignsubmission_file_enabled = 0;
$moduleinfo->submissiondrafts = 0;
$moduleinfo->requiresubmissionstatement = 0;
$moduleinfo->sendnotifications = 0;
$moduleinfo->sendlatenotifications = 0;
$moduleinfo->sendstudentnotifications = 0;
$moduleinfo->teamsubmission = 0;
$moduleinfo->requireallteammemberssubmit = 0;
$moduleinfo->blindmarking = 0;
$moduleinfo->markingworkflow = 0;
$moduleinfo->markingallocation = 0;
$moduleinfo->attemptreopenmethod = 'none';
$moduleinfo->maxattempts = -1;
$moduleinfo->completionsubmit = 0;
```

Call `add_moduleinfo()` and return `$created->coursemodule`.

- [ ] **Step 6: Implement `quiz_factory`**

Set exact first-release behavior:

```php
$moduleinfo->grade = (float)$item->maxgrade;
$moduleinfo->sumgrades = 0;
$moduleinfo->attempts = 1;
$moduleinfo->grademethod = QUIZ_GRADEHIGHEST;
$moduleinfo->timeopen = 0;
$moduleinfo->timeclose = 0;
$moduleinfo->timelimit = 0;
$moduleinfo->overduehandling = 'autosubmit';
$moduleinfo->graceperiod = 0;
$moduleinfo->preferredbehaviour = 'deferredfeedback';
$moduleinfo->canredoquestions = 0;
$moduleinfo->questionsperpage = 1;
$moduleinfo->navmethod = 'free';
$moduleinfo->shuffleanswers = 1;
$moduleinfo->decimalpoints = 2;
$moduleinfo->questiondecimalpoints = -1;
$moduleinfo->showuserpicture = 0;
$moduleinfo->showblocks = 0;
$moduleinfo->password = '';
$moduleinfo->subnet = '';
$moduleinfo->delay1 = 0;
$moduleinfo->delay2 = 0;
$moduleinfo->browsersecurity = '-';
$moduleinfo->attemptonlast = 0;
$moduleinfo->reviewattempt = 0;
$moduleinfo->reviewcorrectness = 0;
$moduleinfo->reviewmarks = 0;
$moduleinfo->reviewspecificfeedback = 0;
$moduleinfo->reviewgeneralfeedback = 0;
$moduleinfo->reviewrightanswer = 0;
$moduleinfo->reviewoverallfeedback = 0;
$moduleinfo->completionattemptsexhausted = 0;
$moduleinfo->completionminattempts = 0;
$moduleinfo->allowofflineattempts = 0;
```

The first release deliberately exposes no answer/review data because the quiz has no questions at creation time. The integration test is the compatibility gate: the resulting quiz must exist, have zero question sum, one attempt, highest-grade method, and the requested final grade.

- [ ] **Step 7: Implement registry allow-list**

```php
public function get(string $modulename): activity_factory_interface {
    switch ($modulename) {
        case target_module::ASSIGN:
            return $this->assign;
        case target_module::QUIZ:
            return $this->quiz;
        default:
            throw new \invalid_parameter_exception('Unsupported target module: ' . $modulename);
    }
}
```

- [ ] **Step 8: Run factory tests**

Run:

```text
vendor/bin/phpunit mod/modeussync/tests/local/activity/assign_factory_test.php
vendor/bin/phpunit mod/modeussync/tests/local/activity/quiz_factory_test.php
```

Expected: PASS, including `grade_items.grademax`.

---

### Task 8: Implement reconciliation, creation state machine, locking, and `/sync`

**Files:**

- Create: `mod_modeussync/classes/local/activity/creation_service.php`
- Create: `mod_modeussync/classes/event/creation_started.php`
- Create: `mod_modeussync/classes/event/activity_created.php`
- Create: `mod_modeussync/classes/event/activity_creation_failed.php`
- Create: `mod_modeussync/classes/event/queue_completed.php`
- Create: `mod_modeussync/classes/event/sync_succeeded.php`
- Create: `mod_modeussync/classes/event/sync_failed.php`
- Test: `mod_modeussync/tests/local/activity/creation_service_test.php`

**Interfaces:**

```php
creation_service::process(int $courseid, int $userid, array $selections): \stdClass
```

Return object:

```php
(object)[
    'queueid' => 10,
    'status' => 'synced',
    'createdcount' => 2,
    'failedcount' => 0,
    'syncattempted' => true,
]
```

- [ ] **Step 1: Write failing state-machine tests**

Cover:

```php
public function test_process_creates_assign_and_quiz_then_syncs_course(): void;
public function test_existing_supported_activity_is_reconciled_without_duplicate(): void;
public function test_existing_unsupported_activity_marks_item_failed(): void;
public function test_partial_failure_preserves_created_items_and_skips_sync(): void;
public function test_retry_creates_only_missing_items(): void;
public function test_sync_failure_sets_sync_failed_and_retry_only_resends_sync(): void;
public function test_invalid_selection_is_rejected_before_state_changes(): void;
public function test_processing_state_is_recovered_by_reconciliation(): void;
```

Inject a fake SyncService:

```php
final class fake_sync_service extends \tool_modeussync\service\SyncService {
    public array $payloads = [];
    public bool $fail = false;

    public function send_sync_courses(array $courses): array {
        $this->payloads[] = $courses;
        if ($this->fail) {
            throw new \moodle_exception('syncrequestfailed', 'tool_modeussync');
        }
        return [];
    }
}
```

Assert the exact payload:

```php
$this->assertSame([[
    'id_modeus' => 'modeus-course-1',
    'id_lms' => $course->idnumber,
]], $syncservice->payloads[0]);
```

- [ ] **Step 2: Run tests and verify failure**

Run:

```text
vendor/bin/phpunit mod/modeussync/tests/local/activity/creation_service_test.php
```

Expected: FAIL because coordinator and events do not exist.

- [ ] **Step 3: Validate selections before acquiring mutable state**

For every selection:

- key must be an integer item ID;
- item must belong to the course queue;
- value must satisfy `target_module::is_supported()`;
- `created` items ignore submitted changes;
- missing selections retain current `targetmodule`.

Call `queue_repository::save_target_modules()` only after complete validation.

- [ ] **Step 4: Implement lock and reconciliation**

Acquire:

```php
$lock = \core\lock\lock_config::get_lock_factory('mod_modeussync')
    ->get_lock('create:' . $courseid, 30);
```

Before creating each item query:

```sql
SELECT cm.id, m.name AS modulename
  FROM {course_modules} cm
  JOIN {modules} m ON m.id = cm.module
 WHERE cm.course = :courseid
   AND cm.idnumber = :externalid
```

Behavior:

- existing `assign`/`quiz` → mark `created`, set actual `targetmodule`, `coursemoduleid`;
- existing other module → mark `failed` with localized conflict error;
- no module → create through registry.

- [ ] **Step 5: Implement partial-success state transitions**

Exact sequence:

1. trigger `creation_started`;
2. save selections;
3. queue → `processing`;
4. process every non-created item independently;
5. success → item `created`, clear error, trigger `activity_created`;
6. exception → item `failed`, save safe error, trigger `activity_creation_failed`;
7. reload all items;
8. if any item is not `created`, queue → `pending`, return without `/sync`;
9. otherwise queue → `awaiting_sync`, trigger `queue_completed`;
10. call `/sync`;
11. success → queue `synced`, set `timesynced`, trigger `sync_succeeded`;
12. failure → queue `sync_failed`, preserve all items, trigger `sync_failed`.

On an already `sync_failed` queue with all items created, skip factory calls and retry only `/sync`.

- [ ] **Step 6: Define exact event metadata**

| Class | `action` | `target` | CRUD | Object table / object ID |
|---|---|---|---|---|
| `creation_started` | `started` | `activity_creation` | `u` | `tool_modeussync_course_queue` / queue ID |
| `activity_created` | `created` | `course_module` | `c` | `course_modules` / cmid |
| `activity_creation_failed` | `failed` | `activity_creation` | `u` | `tool_modeussync_queue_items` / item ID |
| `queue_completed` | `completed` | `assignment_queue` | `u` | `tool_modeussync_course_queue` / queue ID |
| `sync_succeeded` | `succeeded` | `sync_request` | `u` | `tool_modeussync_course_queue` / queue ID |
| `sync_failed` | `failed` | `sync_request` | `u` | `tool_modeussync_course_queue` / queue ID |

All events use `context_course::instance($courseid)`, `LEVEL_TEACHING`, and include only IDs/counts in `other`.

- [ ] **Step 7: Sanitize errors**

Persist and display only:

```php
$safeerror = get_string('activitycreationfailed', 'mod_modeussync', [
    'name' => $item->name,
    'type' => $item->targetmodule,
]);
```

Send full exception class/message/trace to `error_log()` or Moodle debugging with developer mode, never to the UI row.

- [ ] **Step 8: Run coordinator tests**

Run:

```text
vendor/bin/phpunit mod/modeussync/tests/local/activity/creation_service_test.php
```

Expected: all state, retry, conflict, lock, payload, and partial-failure tests PASS.

---

### Task 9: Build the teacher page and POST controller

**Files:**

- Create: `mod_modeussync/view.php`
- Create: `mod_modeussync/index.php`
- Create: `mod_modeussync/renderer.php`
- Create: `mod_modeussync/classes/local/access.php`
- Create: `mod_modeussync/classes/output/queue_page.php`
- Create: `mod_modeussync/templates/queue_page.mustache`
- Test: `mod_modeussync/tests/view_access_test.php`

**Interfaces:**

```php
queue_page::__construct(\stdClass $queue, array $items, \moodle_url $actionurl, bool $canmanage)
queue_page::export_for_template(\renderer_base $output): \stdClass
```

- [ ] **Step 1: Write failing access and output tests**

Cover:

- student cannot view;
- non-editing teacher cannot manage;
- editing teacher can view and manage;
- POST without sesskey is rejected;
- unsupported `targetmodule` is rejected;
- created row has disabled select and activity link;
- pending row defaults to `assign`;
- `sync_failed` page shows «Повторить отправку»;
- all user-visible names are formatted/escaped.

- [ ] **Step 2: Run tests**

Run:

```text
vendor/bin/phpunit mod/modeussync/tests/view_access_test.php
```

Expected: FAIL because controller/output classes do not exist.

- [ ] **Step 3: Implement `view.php` security boundary**

Implement `\mod_modeussync\local\access`:

```php
final class access {
    public static function require_view(\context_module $context): void {
        require_capability('mod/modeussync:view', $context);
    }

    public static function require_manage(\context_module $modulecontext, int $courseid): void {
        require_capability('mod/modeussync:manage', $modulecontext);
        require_capability('moodle/course:manageactivities', \context_course::instance($courseid));
    }
}
```

Controller sequence:

```php
$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('modeussync', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('modeussync', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
\mod_modeussync\local\access::require_view($context);
```

For POST:

```php
if (data_submitted() && optional_param('action', '', PARAM_ALPHA) === 'create') {
    require_sesskey();
    \mod_modeussync\local\access::require_manage($context, $course->id);
    $selections = optional_param_array('targetmodule', [], PARAM_ALPHANUMEXT);
    $result = $service->process($course->id, $USER->id, $selections);
    redirect($PAGE->url, get_string('processresult_' . $result->status, 'mod_modeussync'));
}
```

- [ ] **Step 4: Implement vertical row model**

Every row exported to Mustache:

```php
[
    'id' => (int)$item->id,
    'name' => format_string($item->name, true, ['context' => $context]),
    'maxgrade' => format_float($item->maxgrade, 2),
    'statuslabel' => get_string('status_' . $item->status, 'mod_modeussync'),
    'selectedassign' => $item->targetmodule === 'assign',
    'selectedquiz' => $item->targetmodule === 'quiz',
    'disabled' => $item->status === 'created' || !$canmanage,
    'activityurl' => $item->coursemoduleid
        ? (new moodle_url('/mod/' . $item->targetmodule . '/view.php', ['id' => $item->coursemoduleid]))->out(false)
        : null,
    'error' => $item->lasterror ? s($item->lasterror) : null,
]
```

- [ ] **Step 5: Implement Mustache form**

The template contains:

```mustache
<form method="post" action="{{actionurl}}">
    <input type="hidden" name="sesskey" value="{{sesskey}}">
    <input type="hidden" name="action" value="create">
    <div class="mod-modeussync-queue">
        {{#items}}
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <div class="font-weight-bold">{{name}}</div>
                <div class="text-muted">{{#str}}maxgrade, mod_modeussync{{/str}}: {{maxgrade}}</div>
                <div>{{statuslabel}}</div>
                {{#error}}<div class="text-danger">{{error}}</div>{{/error}}
                {{#activityurl}}<a href="{{activityurl}}">{{#str}}openactivity, mod_modeussync{{/str}}</a>{{/activityurl}}
            </div>
            <select name="targetmodule[{{id}}]" class="custom-select" {{#disabled}}disabled{{/disabled}}>
                <option value="assign" {{#selectedassign}}selected{{/selectedassign}}>{{#str}}typeassign, mod_modeussync{{/str}}</option>
                <option value="quiz" {{#selectedquiz}}selected{{/selectedquiz}}>{{#str}}typequiz, mod_modeussync{{/str}}</option>
            </select>
        </div>
        {{/items}}
    </div>
    {{#canmanage}}
    <button type="submit" class="btn btn-primary">{{buttonlabel}}</button>
    {{/canmanage}}
</form>
```

For disabled selects, add a hidden input with the same value only if the server needs to preserve it; creation service already retains missing selections, so no hidden input is required.

- [ ] **Step 6: Run access/output tests**

Run:

```text
vendor/bin/phpunit mod/modeussync/tests/view_access_test.php
```

Expected: PASS.

---

### Task 10: Keep created activities in `push_courses` but exclude `mod_modeussync`

**Files:**

- Modify: `tool_modeussync/classes/courses_consts.php`
- Modify: `tool_modeussync/classes/task/push_courses.php`
- Test: `tool_modeussync/tests/task/push_courses_test.php`

**Interfaces:**

```php
courses_consts::$non_exportable_module_types = ['modeussync'];
push_courses::getCourseModules($course): array
push_courses::get_module_types(): array
```

- [ ] **Step 1: Write failing export tests**

Create in one course:

- one technical `modeussync`;
- one generated `assign` with `idnumber = meeting-1`;
- one generated `quiz` with `idnumber = meeting-2`.

Assert exported modules contain:

```php
[
    'id' => $assigncmid,
    'lmsIdNumber' => 'meeting-1',
    'name' => 'Assignment',
    'moduleTypeId' => 'assign',
]
```

and equivalent `quiz`; assert no row has `moduleTypeId === 'modeussync'`.

Assert `get_module_types()` contains `assign` and `quiz` but not `modeussync`.

- [ ] **Step 2: Run tests and verify current leak**

Run:

```text
vendor/bin/phpunit admin/tool/modeussync/tests/task/push_courses_test.php
```

Expected: FAIL because current code exports every visible module, including `modeussync`.

- [ ] **Step 3: Add explicit non-exportable list**

```php
public static $non_exportable_module_types = ['modeussync'];
```

In `getCourseModules()`:

```php
if (in_array($courseModuleInfo->modname, courses_consts::$non_exportable_module_types, true)) {
    continue;
}
```

In `get_module_types()`:

```php
if (in_array($moduleType->name, courses_consts::$non_exportable_module_types, true)) {
    continue;
}
```

Do not add `modeussync` to `$unsupported_module_types`, because that array describes inbound creation support and has a different responsibility.

- [ ] **Step 4: Run export tests**

Run:

```text
vendor/bin/phpunit admin/tool/modeussync/tests/task/push_courses_test.php
```

Expected: PASS; generated `assign` and `quiz` still export with `cmid`, `idnumber`, name, and type.

---

### Task 11: Complete language strings, documentation, and full verification

**Files:**

- Modify: `tool_modeussync/lang/en/tool_modeussync.php`
- Create/Modify: `tool_modeussync/lang/ru/tool_modeussync.php`
- Modify: `tool_modeussync/README.md`
- Modify: `mod_modeussync/lang/en/modeussync.php`
- Modify: `mod_modeussync/lang/ru/modeussync.php`
- Create: `mod_modeussync/README.md`
- Verify: all files from Tasks 1–10

**Interfaces:**

- No new runtime interfaces.

- [ ] **Step 1: Add all referenced language keys**

At minimum:

```text
pluginname
modulename
modulenameplural
activityname
manualcreationdisabled
manualdeletiondisabled
singleinstanceonly
cannotacquirelock
typeassign
typequiz
maxgrade
openactivity
create
retrycreation
retrysync
status_pending
status_processing
status_created
status_failed
status_awaiting_sync
status_synced
status_sync_failed
invalidassignmentpayload
invalidassignmentname
unsupportedmodule
moduleidnumberconflict
activitycreationfailed
processresult_pending
processresult_synced
processresult_sync_failed
taskensureinstances
eventassignmentsqueued
eventcreationstarted
eventactivitycreated
eventactivitycreationfailed
eventqueuecompleted
eventsyncsucceeded
eventsyncfailed
```

Russian strings are the primary user-facing copy; English strings must be complete for Moodle fallback.

- [ ] **Step 2: Update documentation**

`tool_modeussync/README.md` must describe:

- `/new-course` returns `results[*].courseData`;
- cron now persists the queue;
- cron does not create assignments and does not call `/sync`;
- dependency on installed `mod_modeussync` for teacher workflow;
- `push_courses` exports generated `assign`/`quiz`, not technical `modeussync`.

`mod_modeussync/README.md` must describe:

- installation dependency;
- one-instance behavior;
- teacher workflow;
- supported types;
- max grade behavior;
- retry semantics;
- required capabilities.

- [ ] **Step 3: Run PHP syntax checks**

From the repository root on Windows PowerShell:

```powershell
Get-ChildItem tool_modeussync,mod_modeussync -Recurse -Filter *.php |
    ForEach-Object { php -l $_.FullName }
```

Expected: every file prints `No syntax errors detected`.

- [ ] **Step 4: Run focused PHPUnit suites**

From Moodle root:

```text
vendor/bin/phpunit admin/tool/modeussync/tests/local/queue/queue_repository_test.php
vendor/bin/phpunit admin/tool/modeussync/tests/local/queue/sync_response_ingestor_test.php
vendor/bin/phpunit admin/tool/modeussync/tests/task/pull_courses_response_test.php
vendor/bin/phpunit admin/tool/modeussync/tests/service/sync_service_logger_test.php
vendor/bin/phpunit admin/tool/modeussync/tests/task/push_courses_test.php
vendor/bin/phpunit mod/modeussync/tests/local/instance_manager_test.php
vendor/bin/phpunit mod/modeussync/tests/local/activity/assign_factory_test.php
vendor/bin/phpunit mod/modeussync/tests/local/activity/quiz_factory_test.php
vendor/bin/phpunit mod/modeussync/tests/local/activity/creation_service_test.php
vendor/bin/phpunit mod/modeussync/tests/view_access_test.php
```

Expected: all tests PASS with zero failures and zero errors.

- [ ] **Step 5: Run complete plugin suites**

```text
vendor/bin/phpunit --testsuite tool_modeussync_testsuite
vendor/bin/phpunit --testsuite mod_modeussync_testsuite
```

Expected: both suites PASS.

- [ ] **Step 6: Run CLI acceptance flow without browser**

Prepare a PHPUnit/integration fixture that:

1. creates a course with non-empty `idnumber`;
2. ingests one `assign` and one `quiz`;
3. verifies one `mod_modeussync`;
4. invokes `creation_service::process()`;
5. verifies both course modules and grade items;
6. verifies `/sync` payload;
7. invokes `push_courses::getCourseModules()`;
8. verifies `assign` and `quiz` export and `modeussync` exclusion;
9. repeats ingest/process and verifies no duplicates.

Run:

```text
vendor/bin/phpunit mod/modeussync/tests/local/activity/creation_service_test.php --filter test_end_to_end_manual_creation_flow
```

Expected: PASS.

- [ ] **Step 7: Verify ADR acceptance checklist**

Confirm with fresh database assertions:

```text
1 queue per course
1 modeussync instance per course
0 automatically created assignments after pull_courses response processing
assign grademax equals courseData.grade
quiz grade and grade_items.grademax equal courseData.grade
quiz sumgrades equals 0 before questions
no duplicate course_modules.idnumber after retry
/sync sent only after all items created
push_courses includes assign and quiz
push_courses excludes modeussync
student access denied
API key absent from collected logs
```

Expected: every assertion is covered by a passing automated test.

---

## Implementation Order and Review Gates

1. Tasks 1–4 complete the `tool_modeussync` persistence and cron-side change.
2. Tasks 5–6 produce a valid, single-instance `mod_modeussync` that appears automatically.
3. Tasks 7–8 implement the core creation behavior and unchanged `/sync`.
4. Task 9 exposes the tested teacher workflow.
5. Task 10 preserves downstream module synchronization.
6. Task 11 is the release gate.

Do not start Task 7 until Tasks 1–6 pass: factories depend on a valid activity plugin and queue. Do not start Task 9 until Task 8 passes: the page must remain a thin controller over a tested service.

## ADR Coverage Matrix

| ADR requirement | Implementation tasks |
|---|---|
| Persist `results[*].courseData` | Tasks 1–3 |
| Stop automatic `mod_assign` creation | Task 3 |
| Stop cron-side `/sync` | Task 3 |
| Reuse tool settings and SyncService | Tasks 4 and 8 |
| Web-safe SyncService logging | Task 4 |
| One `mod_modeussync` per course | Tasks 5–6 |
| No standard manual add | Task 5 |
| Automatic creation and restoration | Task 6 |
| `assign` and empty `quiz` only | Tasks 7–9 |
| Default dropdown value `assign` | Tasks 2 and 9 |
| Immediate `maxgrade = courseData.grade` | Task 7 |
| Modeus ID in `course_modules.idnumber` | Tasks 7–8 |
| Idempotency and reconciliation | Tasks 2, 6, and 8 |
| Partial failure and retry | Task 8 |
| Unchanged `/sync` payload | Task 8 |
| Student access denial and CSRF protection | Task 9 |
| Export generated `assign`/`quiz` through `push_courses` | Task 10 |
| Exclude technical `mod_modeussync` from LmsAdapter | Task 10 |
| Events and audit | Tasks 2 and 8 |
| Migration of existing matching `assign` | Task 8 reconciliation |
| Release verification and documentation | Task 11 |
