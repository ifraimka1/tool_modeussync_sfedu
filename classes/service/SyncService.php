<?php

namespace tool_modeussync\service;

defined('MOODLE_INTERNAL') || die();

class SyncService
{
    /**
     * Maximum amount of request/response body bytes written to Moodle task logs.
     */
    private const MAX_LOG_VALUE_LENGTH = 4096;

    /**
     * Do not let a scheduled task stay in "running" state indefinitely while SyncService is unavailable.
     */
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Upper bound for a single SyncService request from cron.
     */
    private const REQUEST_TIMEOUT_SECONDS = 120;

    /**
     * Endpoint для отправки созданных курсов.
     */
    private const CREATED_COURSES_ENDPOINT = '/new-course';

    /**
     * Endpoint для отправки курсов, в которых сгенерились задания.
     */
    private const SYNC_ENDPOINT = '/sync';

    private function get_base_url(): string
    {
        $baseurl = get_config('tool_modeussync', 'syncservice_base_url');

        if (empty($baseurl)) {
            throw new \moodle_exception(
                'syncservicebaseurlnotset',
                'tool_modeussync',
                '',
                null,
                'SyncService base URL is not configured'
            );
        }

        return rtrim($baseurl, '/');
    }

    /**
     * Отправляет список созданных курсов во внешний сервис.
     *
     * Формат payload:
     * [
     *   ['id_lms' => 123, 'id_modeus' => 'uuid'],
     *   ...
     * ]
     *
     * @param array $courses
     * @return array
     * @throws \moodle_exception
     */
    public function send_created_courses(array $courses): array
    {
        if (empty($courses)) {
            mtrace('SyncService: нет курсов для отправки');
            return [];
        }

        return $this->post_courses(self::CREATED_COURSES_ENDPOINT, $courses, true);
    }

    public function send_sync_courses(array $courses): array
    {
        if (empty($courses)) {
            mtrace('SyncService: нет курсов для отправки на /sync');
            return [];
        }

        return $this->post_courses(self::SYNC_ENDPOINT, $courses, false);
    }

    /**
     * Creates the Moodle HTTP client.
     *
     * @return \curl
     */
    protected function create_curl(): \curl
    {
        return new \curl();
    }

    /**
     * Sends a JSON request to SyncService and validates the response.
     *
     * @param string $endpoint
     * @param array $courses
     * @param bool $resultsrequired
     * @return array
     * @throws \moodle_exception
     */
    private function post_courses(string $endpoint, array $courses, bool $resultsrequired): array
    {
        $apikey = trim((string)get_config('tool_modeussync', 'internal_api_key'));

        if ($apikey === '') {
            throw new \moodle_exception('missinginternalapikey', 'tool_modeussync');
        }

        $url = $this->get_base_url() . $endpoint;
        $payload = array_values($courses);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new \moodle_exception(
                'syncrequestfailed',
                'tool_modeussync',
                '',
                null,
                'JSON encode error: ' . json_last_error_msg()
            );
        }

        mtrace('SyncService POST: ' . $url);
        mtrace('SyncService payload courses count: ' . count($payload));
        $this->trace_log_value('SyncService payload', $body);

        $curl = $this->create_curl();

        $options = [
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Internal-API-Key: ' . $apikey,
            ],
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT_SECONDS,
            'CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT_SECONDS,
        ];

        try {
            $response = $curl->post($url, $body, $options);
        } catch (\Throwable $e) {
            mtrace(
                'SyncService ' . $endpoint . ' request threw ' . get_class($e) . ': ' . $e->getMessage()
            );
            throw $e;
        }

        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? null;
        $errno = (int)$curl->get_errno();

        if ($errno !== 0) {
            $curlerror = trim((string)($curl->error ?? ''));
            $message = 'cURL error ' . $errno . ($curlerror === '' ? '' : ': ' . $curlerror);

            mtrace('SyncService ' . $endpoint . ' ' . $message);
            mtrace('SyncService ' . $endpoint . ' HTTP code: ' . ($httpcode ?? 'unknown'));
            $this->trace_log_value(
                'SyncService ' . $endpoint . ' raw response',
                $response !== null ? (string)$response : 'NULL'
            );

            throw new \moodle_exception('syncrequestfailed', 'tool_modeussync', '', null, $message);
        }

        if ((int)$httpcode < 200 || (int)$httpcode >= 300) {
            $this->trace_http_error_response('SyncService ' . $endpoint . ' response', $httpcode, $response);

            throw new \moodle_exception(
                'syncrequestfailed',
                'tool_modeussync',
                '',
                null,
                $this->format_http_error_message(
                    'SyncService ' . $endpoint . ' returned',
                    $httpcode,
                    (string)$response
                )
            );
        }

        mtrace('SyncService ' . $endpoint . ' response HTTP code: ' . $httpcode);
        $this->trace_log_value(
            'SyncService ' . $endpoint . ' raw response',
            $response !== null ? (string)$response : 'NULL'
        );

        return $this->decode_response($endpoint, $response, $resultsrequired);
    }

    /**
     * Decodes and validates a successful SyncService response.
     *
     * @param string $endpoint
     * @param mixed $response
     * @param bool $resultsrequired
     * @return array
     * @throws \moodle_exception
     */
    private function decode_response(string $endpoint, $response, bool $resultsrequired): array
    {
        if ($response === '' || $response === null) {
            if (!$resultsrequired) {
                return [];
            }

            throw new \moodle_exception(
                'syncrequestfailed',
                'tool_modeussync',
                '',
                null,
                'SyncService ' . $endpoint . ' returned an empty response'
            );
        }

        $decoded = json_decode((string)$response, true);

        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception(
                'syncrequestfailed',
                'tool_modeussync',
                '',
                null,
                'SyncService ' . $endpoint . ' returned invalid JSON: ' . json_last_error_msg() . '. ' .
                    $this->format_exception_value((string)$response)
            );
        }

        $hasresults = array_key_exists('results', $decoded) && is_array($decoded['results']);

        if ($resultsrequired && !$hasresults) {
            throw new \moodle_exception(
                'syncrequestfailed',
                'tool_modeussync',
                '',
                null,
                'SyncService ' . $endpoint . ' response does not contain a results array. ' .
                    $this->format_exception_value((string)$response)
            );
        }

        return $decoded;
    }

    private function trace_log_value(string $label, string $value): void
    {
        foreach ($this->format_log_value($label, $value) as $line) {
            mtrace($line);
        }
    }

    private function trace_http_error_response(string $label, $httpcode, $response): void
    {
        mtrace($label . ' HTTP error code: ' . ($httpcode ?? 'unknown'));
        $this->trace_log_value($label . ' server message', $response !== null ? (string)$response : 'NULL');
    }

    private function format_log_value(string $label, string $value): array
    {
        $length = strlen($value);

        if ($length <= self::MAX_LOG_VALUE_LENGTH) {
            return [$label . ': ' . $value];
        }

        return [
            $label . ' length: ' . $length . ' bytes',
            $label . ' preview: ' . \core_text::substr($value, 0, self::MAX_LOG_VALUE_LENGTH) . '... [truncated]',
        ];
    }

    private function format_exception_value(string $value): string
    {
        $lines = $this->format_log_value('response', $value);

        return implode(' ', $lines);
    }

    private function format_http_error_message(string $prefix, $httpcode, string $response): string
    {
        return $prefix . ' HTTP ' . ($httpcode ?? 'unknown') . '. Response: ' .
            $this->format_exception_value($response);
    }
}
