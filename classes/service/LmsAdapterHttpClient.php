<?php

namespace tool_modeussync\service;

use enrol_lti\local\ltiadvantage\lib\http_client;
use enrol_lti\local\ltiadvantage\lib\issuer_database;
use enrol_lti\local\ltiadvantage\lib\launch_cache_session;
use enrol_lti\local\ltiadvantage\repository\application_registration_repository;
use enrol_lti\local\ltiadvantage\repository\deployment_repository;
use moodle_url;
use Packback\Lti1p3\LtiRegistration;
use Packback\Lti1p3\LtiServiceConnector;
use Packback\Lti1p3\ServiceRequest;
use tool_modeussync\str_utils;

// Сервис, выполняющий запросы к LmsAdapter
class LmsAdapterHttpClient
{
    // LTI deployment id
    public ?string $deploymentId = null;

    // Обертка над http_client для выполнения запросов к LmsAdapter.
    private ?LtiServiceConnector $ltiConnector = null;

    // Данные об LTI подключении между Moodle и LmsAdapter.
    // Является частью официального плагина "Publish as LTI tool" и заимствуется этим плагином для аутентификации/авторизации.
    private ?LtiRegistration $ltiRegistration = null;

    // Client scopes для авторизации в Keycloak.
    //TODO: актуализировать перед релизом.
    private array $requestScopes = ["https://modeus.org/lms/courses"];

    // Адрес адаптера
    private ?string $adapterUrl = null;

    // Загружает настройки и подготавливает клиент для выполнения запросов
    public function initialize()
    {
        global $CFG;
        require_once $CFG->libdir . '/filelib.php';

        $appregistrationrepo = new application_registration_repository();
        $deploymentrepo = new deployment_repository();
        $issuerdb = new issuer_database($appregistrationrepo, $deploymentrepo);
        $appregistrations = $appregistrationrepo->find_all();
        $platformSettings = json_decode(get_config('tool_modeussync', 'connection_settings'));

        foreach ($appregistrations as $appregistration) {
            $deployments = $deploymentrepo->find_all_by_registration($appregistration->get_id());

            foreach ($deployments as $deployment) {
                $deploymentId = $deployment->get_deploymentid();
                if (!isset($platformSettings->$deploymentId) || !isset($platformSettings->$deploymentId->adapterUrl)) {
                    mtrace("Предупреждение: для deployment $deploymentId не указан параметр 'adapterUrl' в настройках, поэтому он будет пропущен");
                    continue;
                }
                $deploymentPlatformSettings = $platformSettings->$deploymentId;
                $this->adapterUrl = str_utils::ensureSlash($deploymentPlatformSettings->adapterUrl);

                $this->ltiRegistration = $issuerdb->findRegistrationByIssuer(
                    $appregistration->get_platformid()->out(false),
                    $appregistration->get_clientid()
                );
                $sesscache = new launch_cache_session();
                $this->ltiConnector = new LtiServiceConnector($sesscache, new http_client(new \curl()));
                $this->deploymentId = $deploymentId;

                break;
            }

            if ($this->ltiConnector !== null) {
                break;
            }
        }

        if ($this->ltiConnector === null) {
            throw new \Exception("Failed to create LtiServiceConnector: there are no deployments with configured platform_settings");
        }
    }

    // queryParams должны быть вида ['deploymentId' => $deployment->get_deploymentid()]
    public function httpGet(string $relativeUrl, array $queryParams): array
    {
        $url = new moodle_url($this->adapterUrl . $relativeUrl, $queryParams);
        $request = new ServiceRequest(
            ServiceRequest::METHOD_GET,
            $url,
            ServiceRequest::TYPE_UNSUPPORTED
        );

        return $this->requestAdapter($request);
    }

    // queryParams должны быть вида ['deploymentId' => $deployment->get_deploymentid()]
    public function httpPost(string $relativeUrl, array $queryParams, ?object $body): array
    {
        $url = new moodle_url($this->adapterUrl . $relativeUrl, $queryParams);
        $request = new ServiceRequest(
            ServiceRequest::METHOD_POST,
            $url,
            ServiceRequest::TYPE_UNSUPPORTED
        );
        if ($body !== null) {
            $body = json_encode($body);
            $request->setBody($body);
        }

        return $this->requestAdapter($request);
    }

    // Выполняет запрос к адаптеру
    private function requestAdapter(ServiceRequest $request): array
    {
        mtrace("{$request->getMethod()}: {$request->getUrl()}");

        $requestResult = $this->ltiConnector->makeServiceRequest($this->ltiRegistration, $this->requestScopes, $request);

        return $requestResult;
    }
}
