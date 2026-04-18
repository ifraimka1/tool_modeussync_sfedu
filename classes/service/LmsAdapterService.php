<?php

namespace tool_modeussync\service;

use GuzzleHttp\Exception\RequestException;

// Сервис, получающий данные из LmsAdapter
class LmsAdapterService
{
    private LmsAdapterHttpClient $lmsHttpClient;

    private string $lmsId;

    function __construct()
    {
        $this->lmsHttpClient = new LmsAdapterHttpClient();
        $this->lmsHttpClient->initialize();
        $this->lmsId = $this->getLmsId();
    }

    public function getLastClosedSession(string $syncSessionType): ?array
    {
        try {
            $response = $this->lmsHttpClient->httpGet("api/v1/lms/{$this->lmsId}/sync-sessions/last-closed", ['type' => $syncSessionType]);
        } catch (RequestException $e) {
            $status = $e->getResponse()->getStatusCode();
            if ($status == 404) {
                return null;
            }
        }

        return $response['body'];
    }

    public function openSession(string $syncSessionType, \DateTime $externalCreatedAt): array
    {
        $safeIntervalSetting = array(
            "PULL_COURSES" => 0,
            "PUSH_COURSES" => get_config('tool_modeussync', 'courses_safe_interval_minutes'),
            "PULL_MEMBERS" => get_config('tool_modeussync', 'users_safe_interval_minutes'),
            "PUSH_GRADES" => get_config('tool_modeussync', 'grades_safe_interval_minutes'),
        );

        $requestBody = new \stdClass();
        $requestBody->syncSessionType = $syncSessionType;
        $externalCreatedAt->setTimezone(new \DateTimeZone('UTC'));
        $requestBody->externalCreatedAt = $externalCreatedAt->format('c');
        $requestBody->syncIntervalMinutes = $safeIntervalSetting[$syncSessionType];
        $response = $this->lmsHttpClient->httpPost("api/v1/lms/{$this->lmsId}/sync-sessions", [], $requestBody);

        return $response['body'];
    }

    public function closeSession(string $id)
    {
        $this->lmsHttpClient->httpPost("api/v1/lms/{$this->lmsId}/sync-sessions/$id/close", [], null);
    }

    private function getLmsId(): string
    {
        mtrace("Получаем lmsId из адаптера...");
        $response = $this->lmsHttpClient->httpGet('api/v1/lms/by-deployment/' . $this->lmsHttpClient->deploymentId, []);
        $lmsId = $response['body']['id'];
        mtrace("LmsId: $lmsId");

        return $lmsId;
    }

    public function getCoursesToCreate(string $sessionId): array
    {
        mtrace("Запрашиваем данные для создания курсов из адаптера...");
        return $this->lmsHttpClient->httpGet("api/v1/lms/{$this->lmsId}/sync-sessions/{$sessionId}/sync/courses", [])['body'];
    }

    public function getCoursesForGradesResync(string $sessionId): array
    {
        mtrace("Запрашиваем идентификаторы курсов для повторной синхронизации оценок...");
        return $this->lmsHttpClient->httpGet("api/v1/lms/{$this->lmsId}/sync-sessions/{$sessionId}/sync/courses-for-grades-resync", [])['body'];
    }

    public function updateCoursesAndModuleTypes(string $sessionId, object $requestBody)
    {
        mtrace("Отправляем данные о курсах и модулях в адаптер...");
        $this->lmsHttpClient->httpPost("api/v1/lms/{$this->lmsId}/sync-sessions/{$sessionId}/sync/courses", [], $requestBody);
    }
    public function getCourseMembers(string $sessionId)
    {
        mtrace("Запрашиваем данные об участниках курсов из адаптера...");
        return $this->lmsHttpClient->httpGet("api/v1/lms/{$this->lmsId}/sync-sessions/{$sessionId}/sync/members", [])['body'];
    }

    public function saveMissingMembers(string $sessionId, object $requestBody)
    {
        mtrace("Отправляем в адаптер потерянных участников курсов...");
        return $this->lmsHttpClient->httpPost("api/v1/lms/{$this->lmsId}/sync-sessions/{$sessionId}/sync/members/missing", [], $requestBody);
    }

    public function pushGrades(string $sessionId, object $requestBody)
    {
        mtrace("Отправляем данные об оценках в адаптер...");
        return $this->lmsHttpClient->httpPost("api/v1/lms/{$this->lmsId}/sync-sessions/{$sessionId}/sync/moodle/grades", [], $requestBody);
    }
}
