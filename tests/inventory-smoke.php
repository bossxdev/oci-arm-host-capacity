<?php
declare(strict_types=1);

require __DIR__ . '/../src/OciConfig.php';
require __DIR__ . '/../src/OciApi.php';

$config = new Hitrov\OciConfig('home', 'user', 'root', 'fingerprint', 'key', null, 'subnet', 'image');
$api = new class extends Hitrov\OciApi {
    public array $calls = [];
    public bool $denyChild = false;

    public function call(
        Hitrov\OciConfig $config,
        string $baseUrl = '',
        string $method = 'GET',
        string $body = null,
        array $params = [],
        ?string &$nextPage = null,
        ?string $retryToken = null
    ) {
        assert($method === 'GET');
        $this->calls[] = [$baseUrl, $params];
        $nextPage = null;
        if (str_contains($baseUrl, 'regionSubscriptions')) {
            return [['regionName' => 'home', 'isHomeRegion' => true, 'status' => 'READY']];
        }
        if (str_contains($baseUrl, 'compartments')) {
            if (!isset($params['page'])) {
                $nextPage = 'second';
                return [['id' => 'child-a', 'lifecycleState' => 'ACTIVE']];
            }
            assert($params['page'] === 'second');
            return [['id' => 'child-b', 'lifecycleState' => 'ACTIVE']];
        }
        if ($this->denyChild && $params['compartmentId'] === 'child-b') {
            throw new RuntimeException('No permission');
        }
        if ($params['compartmentId'] === 'root' && isset($params['page'])) {
            assert($params['page'] === 'more');
            return [['shape' => 'VM.Standard.E2.1.Micro', 'lifecycleState' => 'RUNNING']];
        }
        if ($params['compartmentId'] === 'root') {
            $nextPage = 'more';
            return [['shape' => 'VM.Standard.A1.Flex', 'lifecycleState' => 'TERMINATED']];
        }
        if ($params['compartmentId'] === 'child-a') {
            return [['shape' => 'VM.Standard.A1.Flex', 'lifecycleState' => 'PROVISIONING']];
        }
        if ($params['compartmentId'] === 'child-b') {
            return [['shape' => 'VM.Standard.A1.Flex', 'lifecycleState' => 'RUNNING']];
        }
        throw new RuntimeException('Unexpected page');
    }
};

$api->assertHomeRegion($config);
$instances = $api->getTenancyInstances($config);
assert(count($instances) === 2);
assert($instances[0]['lifecycleState'] === 'PROVISIONING');
assert($instances[1]['lifecycleState'] === 'RUNNING');
assert(count($api->calls) === 7);
$api->denyChild = true;
try {
    $api->getTenancyInstances($config);
    throw new RuntimeException('Incomplete inventory was accepted');
} catch (RuntimeException $e) {
    assert($e->getMessage() === 'No permission');
}
echo "Inventory pagination, home region and fail-closed checks passed\n";
