<?php

namespace tool_modeussync\task\base;

use core\task\scheduled_task;
use tool_modeussync\service\LmsAdapterService;

abstract class base_sync_job extends scheduled_task
{
    protected LmsAdapterService $lmsAdapterService;

    protected array $syncSessionTypeByTaskName = array(
        "pull_courses" => "PULL_COURSES",
        "push_courses" => "PUSH_COURSES",
        "pull_members" => "PULL_MEMBERS",
        "push_grades" => "PUSH_GRADES",
    );

    // Входная точка
    public function execute()
    {
        $current_task_name = $this->get_name();
        $syncSessionType = $this->syncSessionTypeByTaskName[$current_task_name];
        $started = time();
        $started_date = date('c', $started);

        mtrace("\n## Инициализируем фоновую задачу: \"{$current_task_name}\"");
        mtrace("Текущее системное время: {$started_date}");
        $this->lmsAdapterService = new LmsAdapterService();

        mtrace("\n## Получаем последнюю закрытую сессию с типом '{$syncSessionType}'...");
        $lastClosedSession = $this->lmsAdapterService->getLastClosedSession($syncSessionType);
        if ($lastClosedSession === null) {
            mtrace("Закрытых сессий не найдено");
        } else {
            $date = (new \DateTime($lastClosedSession['externalCreatedAt'], new \DateTimeZone("UTC")));
            $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $dateString = $date->format('c');

            mtrace("Последняя закрытая сессия sessionId: {$lastClosedSession['id']} время: {$dateString}");
        }

        mtrace("\n## Определяем временную точку синхронизации для новой сессии...");
        $session_epoch = time();
        $session_date = $this->epochToDate($session_epoch);
        mtrace("Временная точка: {$this->epochToDisplayDateString($session_epoch)}");

        mtrace("\n## Определяем необходимость синхронизации...");
        if (!$this->work_precheck($lastClosedSession)) {
            mtrace("## Предпроверка не была пройдена. Синхронизация будет пропущена;");
            $this->log_results($started);
            return;
        } else {
            mtrace("Предпроверка успешно пройдена.");
        }

        mtrace("\n## Запрашиваем создание сессии '{$syncSessionType}'...");
        $currentSession = $this->lmsAdapterService->openSession($syncSessionType, $session_date);
        mtrace("Идентификатор открытой сессии: {$currentSession['id']}");

        mtrace("\n## Начинаем синхронизацию...");
        if ($this->do_work($currentSession, $lastClosedSession) === true) {
            mtrace("Синхронизация закончена.");
            mtrace("\n## Закрываем сессию синхронизации...");
            $this->lmsAdapterService->closeSession($currentSession['id']);
        } else {
            mtrace("\n## ОШИБКА: Синхронизация завершилась с ошибкой, сессия закрыта не будет");
        }

        $this->log_results($started);
    }

    private function log_results($started)
    {
        $duration = time() - $started;
        $duration_date = gmdate('H:i:s', $duration);
        mtrace('');
        mtrace('## Общее время работы фоновой задачи: ' . $duration_date);
    }

    // Нужна ли (возможна ли) синхронизация
    public function work_precheck(?array $lastClosedSession): bool
    {
        mtrace("Предпроверок не требуется.");
        return true;
    }


    /**
     * Выполнение работы по синхронизации
     * @return bool - Успешна ли синхронизация
     **/
    abstract public function do_work(array $currentSession, ?array $lastClosedSession): bool;

    protected function epochFromSession(?array $session): ?int
    {
        if ($session === null) {
            return null;
        }
        $dateString = $session['externalCreatedAt'];
        $epochTime = (new \DateTime($dateString, new \DateTimeZone('UTC')))->format('U');

        return $epochTime;
    }

    protected function epochToDate($epoch): \DateTime
    {
        $dt = new \DateTime("@$epoch");
        $dt->setTimezone(new \DateTimeZone('UTC'));

        return $dt;
    }

    protected function epochToDateString($epoch): ?string
    {
        if ($epoch === null || $epoch === 0) {
            return null;
        }

        return $this->epochToDate($epoch)->format('c');
    }

    protected function epochToDisplayDateString($epoch): string
    {
        $date = $this->epochToDate($epoch);
        $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return $date->format('c');
    }
}
