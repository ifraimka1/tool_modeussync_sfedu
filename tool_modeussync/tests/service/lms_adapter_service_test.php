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

    private function create_service_with_http_error(int $statuscode): LmsAdapterService {
        $reflection = new ReflectionClass(LmsAdapterService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $httpclientproperty = $reflection->getProperty('lmsHttpClient');
        $httpclientproperty->setAccessible(true);
        $httpclientproperty->setValue($service, new lms_adapter_service_test_http_client($statuscode));

        $lmsidproperty = $reflection->getProperty('lmsId');
        $lmsidproperty->setAccessible(true);
        $lmsidproperty->setValue($service, 'lms-test');

        return $service;
    }
}
