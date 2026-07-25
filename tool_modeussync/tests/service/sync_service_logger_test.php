<?php

defined('MOODLE_INTERNAL') || die();

use tool_modeussync\service\logger\cron_logger;
use tool_modeussync\service\logger\logger_interface;
use tool_modeussync\service\logger\web_logger;
use tool_modeussync\service\SyncService;

/** Collects SyncService messages without emitting output. */
final class collecting_sync_logger implements logger_interface {
    /** @var array */
    public $messages = [];

    public function log(string $message): void {
        $this->messages[] = $message;
    }
}

/** Deterministic Moodle curl double. */
final class sync_service_test_curl extends curl {
    /** @var string|null */
    public $response;

    /** @var array */
    public $info = ['http_code' => 200];

    /** @var int */
    public $errno = 0;

    /** @var string */
    public $error = '';

    /** @var array */
    public $requests = [];

    /** @var Throwable|null */
    public $exception;

    public function post($url, $params = '', $options = []) {
        $this->requests[] = [$url, $params, $options];
        if ($this->exception !== null) {
            throw $this->exception;
        }
        return $this->response;
    }

    public function get_info($opt = 0) {
        return $opt ? ($this->info[$opt] ?? null) : $this->info;
    }

    public function get_errno() {
        return $this->errno;
    }
}

/** SyncService seam injecting the HTTP double. */
final class testable_sync_service extends SyncService {
    /** @var sync_service_test_curl */
    private $testcurl;

    public function __construct(logger_interface $logger, sync_service_test_curl $curl) {
        parent::__construct($logger);
        $this->testcurl = $curl;
    }

    protected function create_curl(): curl {
        return $this->testcurl;
    }
}

/** Tests context-safe SyncService logging without changing HTTP contracts. */
final class sync_service_logger_test extends advanced_testcase {

    public function test_injected_logger_receives_bounded_messages_without_api_key(): void {
        $this->resetAfterTest();
        set_config('syncservice_base_url', 'https://sync.example.test/', 'tool_modeussync');
        set_config('internal_api_key', 'super-secret-key', 'tool_modeussync');

        $logger = new collecting_sync_logger();
        $curl = new sync_service_test_curl();
        $curl->response = json_encode([
            'results' => [],
            'diagnostic' => 'reflected super-secret-key ' . str_repeat('я', 5000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = (new testable_sync_service($logger, $curl))->send_created_courses([
            ['id_lms' => 12, 'id_modeus' => 'modeus-course-1'],
        ]);
        $log = implode("\n", $logger->messages);

        $this->assertSame([], $result['results']);
        $this->assertStringContainsString('https://sync.example.test/new-course', $log);
        $this->assertStringContainsString('courses count: 1', $log);
        $this->assertStringContainsString('preview:', $log);
        $this->assertStringNotContainsString('super-secret-key', $log);

        $previewchecked = false;
        foreach ($logger->messages as $message) {
            $markerposition = strpos($message, 'raw response preview: ');
            if ($markerposition === false) {
                continue;
            }

            $preview = substr($message, $markerposition + strlen('raw response preview: '));
            $preview = substr($preview, 0, -strlen('... [truncated]'));
            $this->assertLessThanOrEqual(4096, strlen($preview));
            $this->assertTrue(mb_check_encoding($preview, 'UTF-8'));
            $previewchecked = true;
        }
        $this->assertTrue($previewchecked, 'A bounded raw-response preview was not logged.');
    }

    public function test_default_logger_is_cron_logger_in_cli_context(): void {
        $this->resetAfterTest();
        if (!defined('CLI_SCRIPT') || !CLI_SCRIPT) {
            $this->markTestSkipped('This assertion requires Moodle CLI PHPUnit.');
        }

        $service = new SyncService();
        $property = new ReflectionProperty(SyncService::class, 'logger');
        $property->setAccessible(true);

        $this->assertInstanceOf(cron_logger::class, $property->getValue($service));
    }

    public function test_web_logger_writes_only_to_server_error_log(): void {
        global $CFG;

        $this->resetAfterTest();
        $logfile = $CFG->dataroot . '/temp/tool_modeussync_web_logger_' . random_string(12) . '.log';
        $previouslog = ini_get('error_log');

        try {
            ini_set('error_log', $logfile);
            (new web_logger())->log('web-safe-message');
            $contents = file_get_contents($logfile);

            $this->assertStringContainsString('[tool_modeussync SyncService] web-safe-message', $contents);
            $source = file_get_contents($CFG->dirroot . '/admin/tool/modeussync/classes/service/logger/web_logger.php');
            $this->assertStringNotContainsString('mtrace', $source);
        } finally {
            ini_set('error_log', $previouslog);
            if (is_file($logfile)) {
                unlink($logfile);
            }
        }
    }

    public function test_created_courses_response_still_requires_results_array(): void {
        $this->resetAfterTest();
        set_config('syncservice_base_url', 'https://sync.example.test', 'tool_modeussync');
        set_config('internal_api_key', 'super-secret-key', 'tool_modeussync');

        $curl = new sync_service_test_curl();
        $curl->response = '{}';

        $this->expectException(moodle_exception::class);
        (new testable_sync_service(new collecting_sync_logger(), $curl))->send_created_courses([
            ['id_lms' => 12, 'id_modeus' => 'modeus-course-1'],
        ]);
    }

    public function test_thrown_http_error_is_replaced_with_sanitized_moodle_exception(): void {
        $this->resetAfterTest();
        set_config('syncservice_base_url', 'https://sync.example.test', 'tool_modeussync');
        set_config('internal_api_key', 'super-secret-key', 'tool_modeussync');
        $logger = new collecting_sync_logger();
        $curl = new sync_service_test_curl();
        $curl->exception = new RuntimeException('Transport reflected super-secret-key');

        try {
            (new testable_sync_service($logger, $curl))->send_created_courses([
                ['id_lms' => 12, 'id_modeus' => 'modeus-course-1'],
            ]);
            $this->fail('Expected sanitized moodle_exception was not thrown.');
        } catch (moodle_exception $exception) {
            $this->assertStringNotContainsString('super-secret-key', $exception->getMessage());
            $this->assertStringNotContainsString('super-secret-key', (string) $exception->debuginfo);
        }
        $this->assertStringNotContainsString('super-secret-key', implode("\n", $logger->messages));
    }

    public function test_curl_error_text_is_sanitized_before_it_is_thrown(): void {
        $this->resetAfterTest();
        set_config('syncservice_base_url', 'https://sync.example.test', 'tool_modeussync');
        set_config('internal_api_key', 'super-secret-key', 'tool_modeussync');
        $logger = new collecting_sync_logger();
        $curl = new sync_service_test_curl();
        $curl->errno = 7;
        $curl->error = 'Connection error reflected super-secret-key';

        try {
            (new testable_sync_service($logger, $curl))->send_created_courses([
                ['id_lms' => 12, 'id_modeus' => 'modeus-course-1'],
            ]);
            $this->fail('Expected sanitized moodle_exception was not thrown.');
        } catch (moodle_exception $exception) {
            $this->assertStringNotContainsString('super-secret-key', $exception->getMessage());
            $this->assertStringNotContainsString('super-secret-key', (string) $exception->debuginfo);
        }
        $this->assertStringNotContainsString('super-secret-key', implode("\n", $logger->messages));
    }

    public function test_sync_endpoint_still_accepts_empty_success_response(): void {
        $this->resetAfterTest();
        set_config('syncservice_base_url', 'https://sync.example.test', 'tool_modeussync');
        set_config('internal_api_key', 'super-secret-key', 'tool_modeussync');

        $curl = new sync_service_test_curl();
        $curl->response = '';

        $result = (new testable_sync_service(new collecting_sync_logger(), $curl))->send_sync_courses([
            ['id_modeus' => 'modeus-course-1', 'id_lms' => 'course-code'],
        ]);

        $this->assertSame([], $result);
        $this->assertSame('https://sync.example.test/sync', $curl->requests[0][0]);
    }
}
