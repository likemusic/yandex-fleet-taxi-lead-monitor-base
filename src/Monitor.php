<?php

namespace Likemusic\YandexFleetTaxi\LeadMonitor;

use Exception;
use Likemusic\YandexFleetTaxi\LeadRepository\Contract\LeadInterface;
use Likemusic\YandexFleetTaxi\LeadRepository\Contract\LeadRepositoryInterface;
use Likemusic\YandexFleetTaxi\LeadRepository\Contract\LeadStatusInterface;
use Likemusic\YandexFleetTaxiClient\Contracts\ClientInterface as YandexFleetTaxiClientInterface;

class Monitor
{
    /**
     * @var LeadRepositoryInterface
     */
    private $leadRepository;

    /**
     * @var YandexFleetTaxiClientInterface
     */
    private $yandexFleetTaxiClient;

    /**
     * @var string
     */
    private $yandexFleetLogin;

    /**
     * @var string
     */
    private $yandexFleetPassword;

    /**
     * @var string
     */
    private $parkId;

    public function __construct(
        LeadRepositoryInterface $leadRepository,
        YandexFleetTaxiClientInterface $yandexFleetTaxiClient,
        string $yandexFleetLogin,
        string $yandexFleetPassword,
        string $parkId
    )
    {
        $this->leadRepository = $leadRepository;
        $this->yandexFleetTaxiClient = $yandexFleetTaxiClient;
        $this->yandexFleetLogin = $yandexFleetLogin;
        $this->yandexFleetPassword = $yandexFleetPassword;
        $this->parkId = $parkId;
    }

    public function run()
    {
        $newLeads = $this->getNewLeads();

        $this->processNewLeads($newLeads);
    }

    private function getNewLeads(): array
    {
        return $this->leadRepository->getNewLeads();
    }

    private function processNewLeads(array $newLeads)
    {
        $this->loginYandexFleetTaxiClient();

        foreach ($newLeads as $newLead) {
            $this->processNewLead($newLead);
        }
    }

    private function loginYandexFleetTaxiClient()
    {
        $login = $this->yandexFleetLogin;
        $password = $this->yandexFleetPassword;
        $this->yandexFleetTaxiClient->login($login, $password);
    }

    private function processNewLead($newLead)
    {
        try {
            $this->setLeadStatusProcessing($newLead);
            $this->registerInFleetTaxiYandexRu($newLead);
            $this->setLeadStatusRegistered($newLead);
        } catch (Exception $exception) {
            $this->setLeadStatusError($newLead, $exception);
        }
    }

    private function setLeadStatusProcessing(LeadInterface $lead)
    {
        $this->updateLeadStatus($lead, LeadStatusInterface::PROCESSING);
    }

    private function updateLeadStatus(LeadInterface $lead, string $status, string $message = null)
    {
        $leadId = $lead->getId();
        $this->leadRepository->updateLeadStatus($leadId, $status, $message);
    }

    private function registerInFleetTaxiYandexRu(LeadInterface $newLead)
    {
        $yandexFleetClient = $this->yandexFleetTaxiClient;
        $parkId = $this->parkId;
        $driverPostData = $newLead->getDriverPostData();

        $driverId = $yandexFleetClient->createDriver($parkId, $driverPostData);

        $carPostData = $newLead->getCarPostData();
        $carId = $yandexFleetClient->storeVehicles($carPostData);

        $yandexFleetClient->bindDriverWithCar($parkId, $driverId, $carId);
    }

    private function setLeadStatusRegistered(LeadInterface $lead)
    {
        $this->updateLeadStatus($lead, LeadStatusInterface::REGISTERED);
    }

    private function setLeadStatusError(LeadInterface $lead, Exception $exception)
    {
        $errorMessage = $this->getErrorMessageByException($exception);
        $this->updateLeadStatus($lead, LeadStatusInterface::ERROR, $errorMessage);
    }

    private function getErrorMessageByException(Exception $exception)
    {
        $errorCode = $exception->getCode();
        $errorMessage = $exception->getMessage();
        $exceptionClass = get_class($exception);

        return "{$exceptionClass}: code:{$errorCode}; message: {$errorMessage}";
    }
}
