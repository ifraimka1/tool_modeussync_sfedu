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

        $apikey = trim((string)get_config('tool_modeussync', 'internal_api_key'));

        if ($apikey === '') {
            throw new \moodle_exception('missinginternalapikey', 'tool_modeussync');
        }

        $url = $this->get_base_url() . self::CREATED_COURSES_ENDPOINT;

        $payload = array_values($courses);

        mtrace('SyncService POST: ' . $url);
        mtrace('SyncService payload courses count: ' . count($payload));
        $this->trace_log_value(
            'SyncService payload',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $curl = new \curl();

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $options = [
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Internal-API-Key: ' . $apikey,
            ],
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT_SECONDS,
            'CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT_SECONDS,
        ];

        $response = $curl->post($url, $body, $options);

        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? null;

        if ((int)$httpcode < 200 || (int)$httpcode >= 300) {
            throw new \moodle_exception(
                'syncservicepostfailed',
                'tool_modeussync',
                '',
                null,
                'SyncService returned HTTP ' . $httpcode . '. Response: ' . $this->format_exception_value((string)$response)
            );
        }

        if ($response === '' || $response === null) {
            return [];
        }

        mtrace('SyncService response HTTP code: ' . $httpcode);
        $this->trace_log_value('SyncService raw response', $response !== null ? (string)$response : 'NULL');

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['raw' => $response];
    }

    public function send_sync_courses(array $courses): array
    {
        if (empty($courses)) {
            mtrace('SyncService: нет курсов для отправки на /sync');
            return [];
        }

        $apikey = trim((string)get_config('tool_modeussync', 'internal_api_key'));

        if ($apikey === '') {
            throw new \moodle_exception('missinginternalapikey', 'tool_modeussync');
        }

        $url = $this->get_base_url() . self::SYNC_ENDPOINT;
        $payload = array_values($courses);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new \moodle_exception(
                'jsonencodefailed',
                'tool_modeussync',
                '',
                null,
                'JSON encode error: ' . json_last_error_msg()
            );
        }

        mtrace('SyncService POST: ' . $url);
        mtrace('SyncService payload courses count: ' . count($payload));
        $this->trace_log_value('SyncService payload', $body);

        $curl = new \curl();

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $options = [
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Internal-API-Key: ' . $apikey,
            ],
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT_SECONDS,
            'CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT_SECONDS,
        ];

        $response = $curl->post($url, $body, $options);

        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? null;

        mtrace('SyncService /sync response HTTP code: ' . $httpcode);
        $this->trace_log_value('SyncService /sync raw response', $response !== null ? (string)$response : 'NULL');

        if ((int)$httpcode < 200 || (int)$httpcode >= 300) {
            throw new \moodle_exception(
                'syncservicepostfailed',
                'tool_modeussync',
                '',
                null,
                'SyncService /sync returned HTTP ' . $httpcode . '. Response: ' . $this->format_exception_value((string)$response)
            );
        }

        if ($response === '' || $response === null) {
            return [];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['raw' => $response];
    }

    private function trace_log_value(string $label, string $value): void
    {
        foreach ($this->format_log_value($label, $value) as $line) {
            mtrace($line);
        }
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
}
