<?php

defined('MOODLE_INTERNAL') || die();

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use tool_modeussync\service\LmsAdapterHttpClient;
use tool_modeussync\service\LmsAdapterService;

/** HTTP client double returning a configured HTTP error. */
final class lms_adapter_service_test_http_client extends LmsAdapterHttpClient {
    /** @var int */
    private $statuscode;

    public function __construct(int $statuscode) {
        $this->statuscode = $statuscode;
    }

    public function httpGet(string $relativeurl, array $queryparams): array {
        $request = new Request('GET', 'https://adapter.example.test/' . $relativeurl);
        $response = new Response($this->statuscode);

        throw new ClientException('HTTP ' . $this->statuscode, $request, $response);
    }
}

/** HTTP client double returning a configured close-session response. */
final class lms_adapter_close_test_http_client extends LmsAdapterHttpClient {
    /** @var array */
    private $response;

    public function __construct(array $response) {
        $this->response = $response;
    }

    public function httpPost(string $relativeurl, array $queryparams, ?object $body): array {
        return $this->response;
    }
}

/** HTTP client double throwing a configured close-session HTTP error. */
final class lms_adapter_close_error_test_http_client extends LmsAdapterHttpClient {
    public function httpPost(string $relativeurl, array $queryparams, ?object $body): array {
        $request = new Request('POST', 'https://adapter.example.test/' . $relativeurl);
        $response = new Response(409, [], '{"message":"Session cannot be closed"}');

        throw new ClientException('HTTP 409', $request, $response);
    }
}

/** Tests handling of adapter responses when looking up the previous sync session. */
final class lms_adapter_service_test extends advanced_testcase {

    public function test_missing_last_closed_session_returns_null(): void {
        $service = $this->create_service_with_http_error(404);

        $this->assertNull($service->getLastClosedSession('PULL_COURSES'));
    }

    public function test_non_404_http_error_is_rethrown(): void {
        $service = $this->create_service_with_http_error(500);

        $this->expectException(ClientException::class);
        $service->getLastClosedSession('PULL_COURSES');
    }

    public function test_close_session_logs_http_status_and_response_body(): void {
        $service = $this->create_service_with_http_client(new lms_adapter_close_test_http_client([
            'status' => 200,
            'body' => ['closed' => true],
        ]));

        $this->expectOutputRegex('/HTTP status: 200.*response body: {"closed":true}/s');

        $service->closeSession('session-1');
    }

    public function test_close_session_logs_empty_response_body(): void {
        $service = $this->create_service_with_http_client(new lms_adapter_close_test_http_client([
            'status' => 200,
            'body' => null,
        ]));

        $this->expectOutputRegex('/HTTP status: 200.*response body: NULL/s');

        $service->closeSession('session-1');
    }

    public function test_close_session_truncates_long_response_body(): void {
        $service = $this->create_service_with_http_client(new lms_adapter_close_test_http_client([
            'status' => 200,
            'body' => str_repeat('x', 5000),
        ]));

        ob_start();
        $service->closeSession('session-1');
        $output = ob_get_clean();

        $this->assertStringContainsString('response body: length: 5000 bytes; preview: ', $output);
        $this->assertStringContainsString('... [truncated]', $output);
        $this->assertStringNotContainsString(str_repeat('x', 4097), $output);
    }

    public function test_close_session_logs_http_error_response_and_rethrows(): void {
        $service = $this->create_service_with_http_client(new lms_adapter_close_error_test_http_client());

        $this->expectOutputRegex(
            '/HTTP status: 409.*response body: {"message":"Session cannot be closed"}/s'
        );
        $this->expectException(ClientException::class);

        $service->closeSession('session-1');
    }

    private function create_service_with_http_error(int $statuscode): LmsAdapterService {
        return $this->create_service_with_http_client(new lms_adapter_service_test_http_client($statuscode));
    }

    private function create_service_with_http_client(LmsAdapterHttpClient $httpclient): LmsAdapterService {
        $reflection = new ReflectionClass(LmsAdapterService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $httpclientproperty = $reflection->getProperty('lmsHttpClient');
        $httpclientproperty->setAccessible(true);
        $httpclientproperty->setValue($service, $httpclient);

        $lmsidproperty = $reflection->getProperty('lmsId');
        $lmsidproperty->setAccessible(true);
        $lmsidproperty->setValue($service, 'lms-test');

        return $service;
    }
}
