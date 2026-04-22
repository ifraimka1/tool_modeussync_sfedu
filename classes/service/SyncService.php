<?php

namespace tool_modeussync\service;

defined('MOODLE_INTERNAL') || die();

class SyncService
{
    /**
     * Базовый URL стороннего сервиса.
     * TODO: заменить на реальный URL.
     */
    private const BASE_URL = 'http://95.174.104.37:4200';

    /**
     * Endpoint для отправки созданных курсов.
     */
    private const CREATED_COURSES_ENDPOINT = '/new-course';

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

        $url = rtrim(self::BASE_URL, '/') . self::CREATED_COURSES_ENDPOINT;

        $payload = array_values($courses);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        mtrace('SyncService POST: ' . $url);
        mtrace('SyncService payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $curl = new \curl();
        $response = $curl->post($url, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $headers);

        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? null;

        if ((int)$httpcode < 200 || (int)$httpcode >= 300) {
            throw new \moodle_exception(
                'syncservicepostfailed',
                'tool_modeussync',
                '',
                null,
                'SyncService returned HTTP ' . $httpcode . '. Response: ' . $response
            );
        }

        if ($response === '' || $response === null) {
            return [];
        }

        mtrace('SyncService response HTTP code: ' . $httpcode);
        mtrace('SyncService raw response: ' . ($response !== null ? $response : 'NULL'));

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['raw' => $response];
    }
}