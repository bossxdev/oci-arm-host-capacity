<?php
declare(strict_types=1);

namespace Hitrov;


use Hitrov\Exception\ApiCallException;
use Hitrov\Exception\CurlException;
use Hitrov\Interfaces\CacheInterface;
use Hitrov\Exception\TooManyRequestsWaiterException;
use Hitrov\Interfaces\TooManyRequestsWaiterInterface;
use Hitrov\OCI\Signer;
use JsonException;

class OciApi
{
    /**
     * @var array
     */
    private $existingInstances;

    private CacheInterface $cache;
    private TooManyRequestsWaiterInterface $waiter;

    /**
     * @param OciConfig $config
     * @param string $shape
     * @param string $sshKey
     * @param string $availabilityDomain
     * @return array
     *
     * @throws ApiCallException
     * @throws JsonException
     * @throws OCI\Exception\PrivateKeyFileNotFoundException
     * @throws OCI\Exception\SignerValidateException
     * @throws OCI\Exception\SigningValidationFailedException
     * @throws CurlException
     */
    public function createInstance(
        OciConfig $config,
        string $shape,
        string $sshKey,
        string $availabilityDomain,
        ?string $retryToken = null,
        ?string $instanceName = null
    ): array
    {
        if ($shape === 'VM.Standard.A1.Flex' &&
            ($config->ocpus < 1 || $config->ocpus > 2 || $config->memoryInGBs < 1 || $config->memoryInGBs > 12)) {
            throw new \InvalidArgumentException('A1 configuration exceeds the Always Free 2 OCPU / 12 GB limit');
        }


        $displayName = $instanceName ?? 'instance-' . date('Ymd-Hi');

        $body = <<<EOD
{
    "metadata": {
        "ssh_authorized_keys": "$sshKey"
    },
    "shape": "$shape",
    "compartmentId": "{$config->tenancyId}",
    "displayName": "$displayName",
    "availabilityDomain": "$availabilityDomain",
    "sourceDetails": {$config->getSourceDetails()},
    "createVnicDetails": {
        "assignPublicIp": false,
        "subnetId": "{$config->subnetId}",
        "assignPrivateDnsRecord": true
    },
    "agentConfig": {
        "pluginsConfig": [
            {
                "name": "Compute Instance Monitoring",
                "desiredState": "ENABLED"
            }
        ],
        "isMonitoringDisabled": false,
        "isManagementDisabled": false
    },
    "definedTags": {},
    "freeformTags": {},
    "instanceOptions": {
        "areLegacyImdsEndpointsDisabled": false
    },
    "availabilityConfig": {
        "recoveryAction": "RESTORE_INSTANCE"
    },
    "shapeConfig": {
        "ocpus": {$config->ocpus},
        "memoryInGBs": {$config->memoryInGBs}
    }
}
EOD;

        $baseUrl = "{$this->getBaseApiUrl($config)}/instances/";

        return $this->call($config, $baseUrl, 'POST', $body, [], $nextPage, $retryToken);
    }

    /**
     * @param OciConfig $config
     * @return array
     *
     * @throws ApiCallException
     * @throws JsonException
     * @throws OCI\Exception\PrivateKeyFileNotFoundException
     * @throws OCI\Exception\SignerValidateException
     * @throws OCI\Exception\SigningValidationFailedException
     */
    public function getInstances(OciConfig $config): array
    {
        $baseUrl = "{$this->getBaseApiUrl($config)}/instances/";
        $params = ['compartmentId' => $config->tenancyId];

        return $this->call($config, $baseUrl, 'GET', null, $params);
    }

    public function getInstance(OciConfig $config, string $id): array
    {
        return $this->call($config, "{$this->getBaseApiUrl($config)}/instances/" . rawurlencode($id));
    }

    public function assertHomeRegion(OciConfig $config): void
    {
        $url = "{$this->getBaseApiUrl($config, 'identity')}/tenancies/{$config->tenancyId}/regionSubscriptions";
        foreach ($this->listPages($config, $url, []) as $subscription) {
            if (($subscription['regionName'] ?? '') === $config->region &&
                ($subscription['isHomeRegion'] ?? false) === true &&
                ($subscription['status'] ?? '') === 'READY') {
                return;
            }
        }
        throw new \UnexpectedValueException('OCI_REGION is not the ready home region');
    }

    public function checkLaunchResources(OciConfig $config): void
    {
        $base = $this->getBaseApiUrl($config);
        $subnet = $this->call($config, "$base/subnets/{$config->subnetId}");
        if (($subnet['id'] ?? null) !== $config->subnetId ||
            ($subnet['lifecycleState'] ?? null) !== 'AVAILABLE') {
            throw new \UnexpectedValueException('OCI subnet is not available');
        }

        $source = json_decode($config->getSourceDetails(), true, 512, JSON_THROW_ON_ERROR);
        if ($source['sourceType'] !== 'image') {
            throw new \UnexpectedValueException('Only image-based launch is supported by preflight');
        }
        if (isset($source['bootVolumeSizeInGBs']) &&
            (!ctype_digit((string) $source['bootVolumeSizeInGBs']) ||
                (int) $source['bootVolumeSizeInGBs'] < 50 ||
                (int) $source['bootVolumeSizeInGBs'] > 200)) {
            throw new \UnexpectedValueException('Boot volume size must be between 50 and 200 GB');
        }
        $image = $this->call($config, "$base/images/{$config->imageId}");
        if (($image['id'] ?? null) !== $config->imageId ||
            ($image['lifecycleState'] ?? null) !== 'AVAILABLE') {
            throw new \UnexpectedValueException('OCI image is not available');
        }
        $compatible = false;
        foreach ($this->listPages($config, "$base/images/{$config->imageId}/shapes", []) as $entry) {
            if (($entry['shape'] ?? null) !== 'VM.Standard.A1.Flex') {
                continue;
            }
            $memory = $entry['memoryConstraints'] ?? [];
            $ocpus = $entry['ocpuConstraints'] ?? [];
            if (($memory['minInGBs'] ?? 0) > $config->memoryInGBs ||
                ($memory['maxInGBs'] ?? PHP_INT_MAX) < $config->memoryInGBs ||
                ($ocpus['min'] ?? 0) > $config->ocpus ||
                ($ocpus['max'] ?? PHP_INT_MAX) < $config->ocpus) {
                throw new \UnexpectedValueException('OCI image does not support configured A1 resources');
            }
            $compatible = true;
        }
        if (!$compatible) {
            throw new \UnexpectedValueException('OCI image is not compatible with A1');
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getTenancyInstances(OciConfig $config): array
    {
        $compartments = $this->listPages(
            $config,
            "{$this->getBaseApiUrl($config, 'identity')}/compartments/",
            ['compartmentId' => $config->tenancyId, 'compartmentIdInSubtree' => 'true', 'accessLevel' => 'ANY']
        );

        $instances = [];
        foreach (array_merge([['id' => $config->tenancyId]], $compartments) as $compartment) {
            if (!is_array($compartment) || empty($compartment['id'])) {
                throw new \UnexpectedValueException('OCI compartment response has no id');
            }
            if (($compartment['lifecycleState'] ?? 'ACTIVE') === 'DELETED') {
                continue;
            }
            foreach ($this->listPages(
                $config,
                "{$this->getBaseApiUrl($config)}/instances/",
                ['compartmentId' => $compartment['id']]
            ) as $instance) {
                if (!is_array($instance) || !isset($instance['shape'], $instance['lifecycleState'])) {
                    throw new \UnexpectedValueException('OCI instance response is incomplete');
                }
                if ($instance['shape'] === 'VM.Standard.A1.Flex' && $instance['lifecycleState'] !== 'TERMINATED') {
                    $instances[] = $instance;
                }
            }
        }

        return $instances;
    }

    private function listPages(OciConfig $config, string $url, array $params): array
    {
        $items = [];
        $seenPages = [];
        $page = null;
        do {
            if ($page !== null) {
                if (isset($seenPages[$page])) {
                    throw new \UnexpectedValueException('OCI pagination did not advance');
                }
                $seenPages[$page] = true;
                $params['page'] = $page;
            }
            $nextPage = null;
            $batch = $this->call($config, $url, 'GET', null, $params, $nextPage);
            if (!is_array($batch) || array_values($batch) !== $batch) {
                throw new \UnexpectedValueException('OCI list response must be an array');
            }
            array_push($items, ...$batch);
            if ($nextPage !== null && $nextPage === $page) {
                throw new \UnexpectedValueException('OCI pagination did not advance');
            }
            $page = $nextPage;
        } while ($page !== null && $page !== '');

        return $items;
    }

    public function checkExistingInstances(OciConfig $config, array $listResponse, string $shape, int $maxRunningInstancesOfThatShape): string
    {
        $this->existingInstances = array_filter($listResponse, function ($instance) use ($shape) {
//        $unacceptableStates = ['RUNNING', 'PROVISIONING', 'STARTING', 'STOPPED', 'STOPPING', 'TERMINATING'];
            $acceptableStates = ['TERMINATED'];
            return !in_array($instance['lifecycleState'], $acceptableStates) && $instance['shape'] === $shape;
        });

        if (count($this->existingInstances) < $maxRunningInstancesOfThatShape) {
            return '';
        }

        $displayNames = array_map(function ($instance) {
            return $instance['displayName'];
        }, $this->existingInstances);
        $displayNamesString = implode(', ', $displayNames);

        $lifecycleStates = array_map(function ($instance) {
            return $instance['lifecycleState'];
        }, $this->existingInstances);
        $lifecycleStatesString = implode(', ', $lifecycleStates);

        return "Already have an instance(s) [$displayNamesString] in state(s) (respectively) [$lifecycleStatesString]. User: $config->ociUserId\n";
    }

    /**
     * @param OciConfig $config
     * @return array
     *
     * @throws ApiCallException
     * @throws JsonException
     * @throws OCI\Exception\PrivateKeyFileNotFoundException
     * @throws OCI\Exception\SignerValidateException
     * @throws OCI\Exception\SigningValidationFailedException
     */
    public function getAvailabilityDomains(OciConfig $config): array
    {
        $data = null;
        if (getenv('CACHE_AVAILABILITY_DOMAINS') && isset($this->cache)) {
            $data = $this->cache->get('getAvailabilityDomains');
        }

        if (!$data) {
            $baseUrl = "{$this->getBaseApiUrl($config, 'identity')}/availabilityDomains/";
            $params = ['compartmentId' => $config->tenancyId];

            $data = $this->call($config, $baseUrl, 'GET', null, $params);
            if (getenv('CACHE_AVAILABILITY_DOMAINS') && isset($this->cache)) {
                $this->cache->add($data, 'getAvailabilityDomains');
            }
        }

        return $data;
    }

    /**
     * @return array
     */
    public function getExistingInstances(): array
    {
        return $this->existingInstances;
    }

    public function setCache(CacheInterface $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * @param OciConfig $config
     * @param string $baseUrl
     * @param string $method
     * @param string|null $body
     * @param array $params
     * @return array
     *
     * @throws ApiCallException
     * @throws JsonException
     * @throws OCI\Exception\PrivateKeyFileNotFoundException
     * @throws OCI\Exception\SignerValidateException
     * @throws OCI\Exception\SigningValidationFailedException
     * @throws CurlException
     */
    public function call(
        OciConfig $config,
        string $baseUrl = '',
        string $method = 'GET',
        string $body = null,
        array $params = [],
        ?string &$nextPage = null,
        ?string $retryToken = null
    )
    {
        if (isset($this->waiter) && $this->waiter->isConfigured() && $this->waiter->isTooEarly()) {
            throw new TooManyRequestsWaiterException("Will retry after {$this->waiter->secondsRemaining()} seconds");
        }
        $paramsString = '';
        if ($params) {
            $paramsString = '?' . http_build_query($params);
        }

        $url = "$baseUrl$paramsString";

        $signer = new Signer(
            $config->tenancyId,
            $config->ociUserId,
            $config->keyFingerPrint,
            $config->privateKeyFilename
        );

        $headers = $signer->getHeaders($url, $method, $body, 'application/json');
        if ($retryToken !== null) {
            $headers[] = 'opc-retry-token: ' . $retryToken;
        }

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 1,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($body) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        try {
            return $this->send($curlOptions, $nextPage);
        } catch (ApiCallException $e) {
            if ($e->getCode() === 429 && isset($this->waiter) && $this->waiter->isConfigured()) {
                $this->waiter->enable();
                throw new TooManyRequestsWaiterException('OCI rate limited; retry after cooldown', 429, $e);
            }
            throw $e;
        }
    }

    protected function send(array $curlOptions, ?string &$nextPage = null): array
    {
        return HttpClient::getResponse($curlOptions, $nextPage);
    }

    public function setWaiter(TooManyRequestsWaiterInterface $waiter): void
    {
        $this->waiter = $waiter;
    }

    private function getBaseApiUrl(OciConfig $config, string $api = 'iaas'): string
    {
        return "https://$api.{$config->region}.oraclecloud.com/20160918";
    }
}
