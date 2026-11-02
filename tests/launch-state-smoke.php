<?php
declare(strict_types=1);

require __DIR__ . '/../src/LaunchState.php';
require __DIR__ . '/../src/OciConfig.php';
require __DIR__ . '/../src/OciApi.php';

$directory = sys_get_temp_dir() . '/oci-launch-test-' . bin2hex(random_bytes(8));
mkdir($directory);
try {
    $state = new Hitrov\LaunchState($directory);
    $attempt = $state->begin();
    assert(strlen($attempt['token']) === 32);
    assert($state->current()['display_name'] === $attempt['display_name']);

    try {
        new Hitrov\LaunchState($directory);
        throw new RuntimeException('Concurrent launch was allowed');
    } catch (RuntimeException $e) {
        assert($e->getMessage() === 'Another provisioning process is running');
    }

    $api = new class extends Hitrov\OciApi {
        public array $calls = [];
        public function call(
            Hitrov\OciConfig $config,
            string $baseUrl = '',
            string $method = 'GET',
            string $body = null,
            array $params = [],
            ?string &$nextPage = null,
            ?string $retryToken = null
        ) {
            $this->calls[] = [$method, json_decode($body, true), $retryToken];
            throw new Hitrov\Exception\CurlException('Connection timed out');
        }
    };
    require __DIR__ . '/../src/Exception/CurlException.php';
    $config = new Hitrov\OciConfig('r', 'u', 't', 'f', 'key', null, 'subnet', 'image');
    try {
        $api->createInstance($config, 'VM.Standard.A1.Flex', 'ssh-key', 'ad', $attempt['token'], $attempt['display_name']);
        throw new RuntimeException('Timeout not propagated');
    } catch (Hitrov\Exception\CurlException $e) {
        assert($e->getMessage() === 'Connection timed out');
    }
    assert(count($api->calls) === 1);
    assert($api->calls[0][0] === 'POST');
    assert($api->calls[0][1]['displayName'] === $attempt['display_name']);
    assert($api->calls[0][2] === $attempt['token']);
    unset($state);

    $restarted = new Hitrov\LaunchState($directory);
    assert($restarted->current()['token'] === $attempt['token']);
    try {
        $restarted->begin();
        throw new RuntimeException('Duplicate launch was allowed');
    } catch (RuntimeException $e) {
        assert($e->getMessage() === 'Previous launch needs reconciliation');
    }
    $restarted->succeeded();
    unset($restarted);

    $again = new Hitrov\LaunchState($directory);
    $again->capacityRejected();
    assert($again->secondsUntilRetry() === 0);
    $first = $again->scheduleRetry();
    $second = $again->scheduleRetry();
    $third = $again->scheduleRetry();
    $fourth = $again->scheduleRetry();
    $fifth = $again->scheduleRetry();
    assert([$first, $second, $third, $fourth, $fifth] === [300, 600, 1200, 2400, 3600]);
    assert($again->secondsUntilRetry() > 3500);
    $again->clearRetry();
    assert($again->secondsUntilRetry() === 0);
    unset($again);

    $resumed = new Hitrov\LaunchState($directory);
    assert($resumed->secondsUntilRetry() === 0);
    $resumed->scheduleRetry();
    unset($resumed);
    $persisted = new Hitrov\LaunchState($directory);
    assert($persisted->secondsUntilRetry() > 250);
    $persisted->clearRetry();
    unset($restarted);
    echo "Pending launch survives timeout/restart and prevents duplicate POST\n";
} finally {
    @unlink($directory . '/launch.lock');
    @unlink($directory . '/launch.sqlite');
    @rmdir($directory);
}
