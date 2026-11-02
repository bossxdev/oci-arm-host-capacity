<?php
declare(strict_types=1);


// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;
use Hitrov\InstanceMonitor;
use Hitrov\LaunchState;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

$dryRun = getenv('DRY_RUN') !== 'false';
// Container execution is read-only unless provisioning is explicitly enabled.
if (getenv('OCI_CONTAINER_MODE') === '1' && !$dryRun && getenv('OCI_ENABLE_PROVISIONING') !== 'true') {
    echo "Provisioning disabled; set DRY_RUN=true to validate OCI read-only.\n";
    return;
}

if ($dryRun || getenv('OCI_CONTAINER_MODE') === '1') {
    $required = [
        'OCI_REGION', 'OCI_USER_ID', 'OCI_TENANCY_ID', 'OCI_KEY_FINGERPRINT',
        'OCI_PRIVATE_KEY_FILENAME', 'OCI_SUBNET_ID', 'OCI_IMAGE_ID', 'OCI_SSH_PUBLIC_KEY',
    ];
    foreach ($required as $name) {
        if (getenv($name) === false || trim((string) getenv($name)) === '') {
            throw new \InvalidArgumentException("Missing $name");
        }
    }
}
/*
 * No need to modify any value in this file anymore!
 * Copy .env.example to .env and adjust there instead.
 *
 * README.md now has all the information.
 */
$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null, // null or '' or 'jYtI:PHX-AD-1' or ['jYtI:PHX-AD-1','jYtI:PHX-AD-2']
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

if ($dryRun || getenv('OCI_CONTAINER_MODE') === '1') {
    if (getenv('OCI_SHAPE') !== 'VM.Standard.A1.Flex' ||
        $config->ocpus < 1 || $config->ocpus > 2 ||
        $config->memoryInGBs < 1 || $config->memoryInGBs > 12 ||
        (getenv('OCI_MAX_INSTANCES') !== false && (int) getenv('OCI_MAX_INSTANCES') !== 1)) {
        throw new \InvalidArgumentException('Only one Always Free A1 instance (up to 2 OCPU / 12 GB) is supported');
    }
    $config->getSourceDetails();
}

$api = new OciApi();
if (!$dryRun && getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
$waiter = new TooManyRequestsWaiter((int) (getenv('TOO_MANY_REQUESTS_TIME_WAIT') ?: 600));
$api->setWaiter($waiter);
if ($waiter->isTooEarly()) {
    throw new \Hitrov\Exception\TooManyRequestsWaiterException("Will retry after {$waiter->secondsRemaining()} seconds");
}
$notifier = (function (): \Hitrov\Interfaces\NotifierInterface {
    /*
     * if you have own https://core.telegram.org/bots
     * and set TELEGRAM_BOT_API_KEY and your TELEGRAM_USER_ID in .env
     *
     * then you can get notified when script will succeed.
     * otherwise - don't mind OR develop you own NotifierInterface
     * to e.g. send SMS or email.
     */
    return new \Hitrov\Notification\Telegram();
})();

$shape = getenv('OCI_SHAPE');

$notifyFailure = static function (string $message) use ($notifier): void {
    if (!$notifier->isSupported()) {
        return;
    }
    try {
        $notifier->notify("[OCI] $message");
    } catch (\Throwable $e) {
        fwrite(STDERR, "Telegram delivery failed; notification dropped.\n");
    }
};


$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

$launchState = $dryRun ? null : new LaunchState(getcwd());
$api->assertHomeRegion($config);
$instances = $api->getTenancyInstances($config);
if ($launchState !== null && ($pending = $launchState->current()) !== null) {
    if (empty($pending['instance_id'])) {
        foreach ($instances as $instance) {
            if (($instance['displayName'] ?? '') === $pending['display_name'] && !empty($instance['id'])) {
                $launchState->observed($instance['id']);
                break;
            }
        }
        if (empty($launchState->current()['instance_id'])) {
            $notifyFailure('Previous launch result is unknown; manual inspection required.');
            throw new \RuntimeException('Previous launch result is unknown; inspect OCI before retrying');
        }
    }
    InstanceMonitor::check($api, $config, $launchState, $notifier);
    return;
}

$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances && !$dryRun) {
    echo "$existingInstances\n";
    return;
}

if (!$dryRun && !empty($config->availabilityDomains)) {
    if (is_array($config->availabilityDomains)) {
        $availabilityDomains = $config->availabilityDomains;
    } else {
        $availabilityDomains = [ $config->availabilityDomains ];
    }
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}

$api->checkLaunchResources($config);

if ($dryRun) {
    echo 'Dry-run: validated OCI access, found ' . count($instances) . ' active A1 instance(s) across the tenancy and ' . count($availabilityDomains) . " availability domain(s); no launch sent.\n";
    return;
}

$waitSeconds = $launchState->secondsUntilRetry();
if ($waitSeconds > 0) {
    echo "Capacity backoff active; retry in {$waitSeconds} seconds.\n";
    $notifyFailure("Capacity backoff active; next attempt in {$waitSeconds} seconds.");
    return;
}
$launchState->clearRetry();

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    $attempt = $launchState->begin();
    try {
        $instanceDetails = $api->createInstance($config, $shape, getenv('OCI_SSH_PUBLIC_KEY'), $availabilityDomain, $attempt['token'], $attempt['display_name']);
    } catch (\Hitrov\Exception\TooManyRequestsWaiterException $e) {
        if ($e->getCode() === 429) {
            $launchState->capacityRejected(); // OCI explicitly rejected this request as throttled.
            $notifyFailure('OCI rate limited (429); cooldown started. Attempt cleared.');
        }
        throw $e;
    } catch (ApiCallException $e) {
        $response = json_decode($e->getMessage(), true);
        if ($e->getCode() === 500 &&
            ($response['code'] ?? '') === 'InternalError' &&
            ($response['message'] ?? '') === 'Out of host capacity.') {
            $launchState->capacityRejected();
            sleep(16);
            continue;
        }
        $notifyFailure("Launch failed: HTTP {$e->getCode()}. Attempt stays pending; manual inspection required.");
        throw $e;
    } catch (\Hitrov\Exception\CurlException $e) {
        $notifyFailure('Launch result unknown (transport error). Attempt stays pending; manual inspection required.');
        throw $e;
    }

    if (empty($instanceDetails['id']) || ($instanceDetails['displayName'] ?? '') !== $attempt['display_name']) {
        $notifyFailure('Launch response missing expected instance identity; manual inspection required.');
        throw new \UnexpectedValueException('Launch response has no matching instance ID');
    }
    $launchState->observed($instanceDetails['id']);
    InstanceMonitor::check($api, $config, $launchState, $notifier);
    return;
}

$minutes = $launchState->scheduleRetry();
echo "Out of host capacity in every availability domain; next attempt in {$minutes} seconds.\n";
$notifyFailure("Out of host capacity in every availability domain; next attempt in {$minutes} seconds.");
