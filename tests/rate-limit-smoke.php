<?php
declare(strict_types=1);

namespace Hitrov\OCI {
    class Signer
    {
        public function __construct(...$args) {}
        public function getHeaders(...$args): array { return []; }
    }
}

namespace {
require __DIR__ . '/../src/Interfaces/TooManyRequestsWaiterInterface.php';
require __DIR__ . '/../src/Exception/ApiCallException.php';
require __DIR__ . '/../src/Exception/TooManyRequestsWaiterException.php';
require __DIR__ . '/../src/TooManyRequestsWaiter.php';
require __DIR__ . '/../src/OciConfig.php';
require __DIR__ . '/../src/OciApi.php';

$directory = sys_get_temp_dir() . '/oci-rate-limit-test-' . bin2hex(random_bytes(8));
mkdir($directory);
$before = getcwd();
chdir($directory);
try {
    $api = new class extends Hitrov\OciApi {
        public int $sent = 0;
        protected function send(array $curlOptions, ?string &$nextPage = null): array
        {
            ++$this->sent;
            throw new Hitrov\Exception\ApiCallException('TooManyRequests', 429);
        }
    };
    $waiter = new Hitrov\TooManyRequestsWaiter(60);
    $api->setWaiter($waiter);
    $config = new Hitrov\OciConfig('home', 'user', 'root', 'fingerprint', 'key', null, 'subnet', 'image');
    try {
        $api->call($config, 'https://identity.home.oraclecloud.com/20160918/compartments');
        throw new RuntimeException('429 did not stop inventory');
    } catch (Hitrov\Exception\TooManyRequestsWaiterException $e) {
        assert($e->getCode() === 429);
    }
    assert($api->sent === 1 && $waiter->isTooEarly());
    try {
        $api->call($config, 'https://iaas.home.oraclecloud.com/20160918/instances');
        throw new RuntimeException('GET bypassed cooldown');
    } catch (Hitrov\Exception\TooManyRequestsWaiterException $e) {
        assert($e->getCode() !== 429);
    }
    try {
        $api->createInstance($config, 'VM.Standard.A1.Flex', 'ssh-key', 'ad');
        throw new RuntimeException('POST bypassed cooldown');
    } catch (Hitrov\Exception\TooManyRequestsWaiterException $e) {
        assert($e->getCode() !== 429);
    }
    assert($api->sent === 1);
    $restarted = new Hitrov\TooManyRequestsWaiter(60);
    assert($restarted->isTooEarly() && $restarted->secondsRemaining() > 0);
    echo "429 persists cooldown; no inventory or launch requests during cooldown\n";
} finally {
    chdir($before);
    @unlink($directory . '/too_many_requests_waiter.txt');
    @rmdir($directory);
}
}
