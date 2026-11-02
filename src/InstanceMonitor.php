<?php
declare(strict_types=1);

namespace Hitrov;

use Hitrov\Interfaces\NotifierInterface;

final class InstanceMonitor
{
    public static function check(OciApi $api, OciConfig $config, LaunchState $state, NotifierInterface $notifier): void
    {
        $attempt = $state->current();
        if ($attempt === null || empty($attempt['instance_id'])) {
            throw new \RuntimeException('Launch has no instance ID; inspect OCI inventory');
        }
        $instance = $api->getInstance($config, $attempt['instance_id']);
        if (($instance['id'] ?? '') !== $attempt['instance_id'] ||
            ($instance['displayName'] ?? '') !== $attempt['display_name']) {
            throw new \UnexpectedValueException('OCI instance identity does not match saved launch');
        }
        $lifecycle = $instance['lifecycleState'] ?? '';
        if ($lifecycle !== 'RUNNING') {
            if (in_array($lifecycle, ['PROVISIONING', 'STARTING'], true)) {
                echo "Instance $lifecycle; check again later.\n";
                return;
            }
            throw new \RuntimeException("Instance $lifecycle requires manual investigation");
        }

        $state->succeeded();
        echo "Instance RUNNING: {$attempt['instance_id']}\n";
        if (!$notifier->isSupported() || (int) $state->current()['notified'] === 1) {
            return;
        }
        // Telegram has no idempotency token: an acknowledged send before this commit can be duplicated after a crash.
        try {
            $notifier->notify("[OCI] Instance RUNNING\nName: {$attempt['display_name']}\nOCID: {$attempt['instance_id']}\nRegion: {$config->region}\nShape: VM.Standard.A1.Flex\nOCPU: {$config->ocpus}\nMemory: {$config->memoryInGBs} GB");
            $state->notified();
        } catch (\Throwable $e) {
            fwrite(STDERR, "Telegram delivery failed; notification remains pending.\n");
        }
    }
}
