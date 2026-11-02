<?php
declare(strict_types=1);

require __DIR__ . '/../src/Interfaces/NotifierInterface.php';
require __DIR__ . '/../src/LaunchState.php';
require __DIR__ . '/../src/OciConfig.php';
require __DIR__ . '/../src/OciApi.php';
require __DIR__ . '/../src/InstanceMonitor.php';

$directory = sys_get_temp_dir() . '/oci-monitor-test-' . bin2hex(random_bytes(8));
mkdir($directory);
try {
    $state = new Hitrov\LaunchState($directory);
    $attempt = $state->begin();
    $state->observed('instance-1');
    $config = new Hitrov\OciConfig('home', 'user', 'root', 'fingerprint', 'key', null, 'subnet', 'image');
    $api = new class extends Hitrov\OciApi {
        public string $lifecycle = 'PROVISIONING';
        public string $name = '';
        public function getInstance(Hitrov\OciConfig $config, string $id): array
        {
            return ['id' => $id, 'displayName' => $this->name, 'lifecycleState' => $this->lifecycle];
        }
    };
    $api->name = $attempt['display_name'];
    $notifier = new class implements Hitrov\Interfaces\NotifierInterface {
        public int $calls = 0;
        public bool $fail = true;
        public function isSupported(): bool { return true; }
        public function notify(string $message): array
        {
            ++$this->calls;
            assert(str_contains($message, 'instance-1'));
            if ($this->fail) throw new RuntimeException('Telegram offline');
            return ['ok' => true];
        }
    };
    Hitrov\InstanceMonitor::check($api, $config, $state, $notifier);
    assert($state->current()['status'] === 'provisioning' && $notifier->calls === 0);
    $api->lifecycle = 'RUNNING';
    Hitrov\InstanceMonitor::check($api, $config, $state, $notifier);
    assert($state->current()['status'] === 'succeeded' && (int) $state->current()['notified'] === 0);
    unset($state);

    $restarted = new Hitrov\LaunchState($directory);
    $notifier->fail = false;
    Hitrov\InstanceMonitor::check($api, $config, $restarted, $notifier);
    assert((int) $restarted->current()['notified'] === 1 && $notifier->calls === 2);
    Hitrov\InstanceMonitor::check($api, $config, $restarted, $notifier);
    assert($notifier->calls === 2);
    try {
        $restarted->begin();
        throw new RuntimeException('Duplicate launch allowed');
    } catch (RuntimeException $e) {
        assert($e->getMessage() === 'Previous launch needs reconciliation');
    }
    unset($restarted);
    echo "RUNNING notification retries after failure, then sends once per persisted acknowledgment\n";
} finally {
    @unlink($directory . '/launch.lock');
    @unlink($directory . '/launch.sqlite');
    @rmdir($directory);
}
