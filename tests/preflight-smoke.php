<?php
declare(strict_types=1);

require __DIR__ . '/../src/OciConfig.php';
require __DIR__ . '/../src/OciApi.php';

$config = new Hitrov\OciConfig('home', 'user', 'root', 'fingerprint', 'key', null, 'subnet', 'image');
$api = new class extends Hitrov\OciApi {
    public array $calls = [];
    public array $subnet = ['id' => 'subnet', 'lifecycleState' => 'AVAILABLE'];
    public array $image = ['id' => 'image', 'lifecycleState' => 'AVAILABLE'];
    public array $shapes = [
        ['shape' => 'VM.Standard.A1.Flex', 'memoryConstraints' => ['minInGBs' => 8], 'ocpuConstraints' => ['min' => 1]],
    ];

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
        $this->calls[] = $baseUrl;
        $nextPage = null;
        if (str_contains($baseUrl, '/subnets/')) return $this->subnet;
        if (str_contains($baseUrl, '/shapes')) return $this->shapes;
        if (str_contains($baseUrl, '/images/')) return $this->image;
        throw new RuntimeException('Unexpected request');
    }
};

$api->checkLaunchResources($config);
assert(count($api->calls) === 3);
$api->image['lifecycleState'] = 'DISABLED';
try {
    $api->checkLaunchResources($config);
    throw new RuntimeException('Disabled image accepted');
} catch (UnexpectedValueException $e) {
    assert($e->getMessage() === 'OCI image is not available');
}
$api->image['lifecycleState'] = 'AVAILABLE';
$api->shapes[0]['memoryConstraints']['minInGBs'] = 16;
try {
    $api->checkLaunchResources($config);
    throw new RuntimeException('Incompatible image accepted');
} catch (UnexpectedValueException $e) {
    assert($e->getMessage() === 'OCI image does not support configured A1 resources');
}
$api->shapes[0]['memoryConstraints']['minInGBs'] = 8;
$config->setBootVolumeSizeInGBs('201');
try {
    $api->checkLaunchResources($config);
    throw new RuntimeException('Oversized boot volume accepted');
} catch (UnexpectedValueException $e) {
    assert($e->getMessage() === 'Boot volume size must be between 50 and 200 GB');
}
echo "Preflight image/subnet compatibility and boot-volume bounds passed\n";
