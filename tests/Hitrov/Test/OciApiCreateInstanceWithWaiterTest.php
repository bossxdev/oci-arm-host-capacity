<?php
declare(strict_types=1);

namespace Hitrov\Test;

use Hitrov\Exception\ApiCallException;
use Hitrov\Exception\TooManyRequestsWaiterException;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\Test\Traits\DefaultConfig;
use Hitrov\TooManyRequestsWaiter;
use PHPUnit\Framework\TestCase;

class OciApiCreateInstanceWithWaiterTest extends TestCase
{
    use DefaultConfig;

    private static ?string $keyFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteFile();

        if (self::$keyFile === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            self::$keyFile = tempnam(sys_get_temp_dir(), 'oci-test-key-');
            file_put_contents(self::$keyFile, $pem);
        }

        putenv('OCI_REGION=us-phoenix-1');
        putenv('OCI_USER_ID=ocid1.user.oc1..test');
        putenv('OCI_TENANCY_ID=ocid1.tenancy.oc1..test');
        putenv('OCI_KEY_FINGERPRINT=aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99');
        putenv('OCI_PRIVATE_KEY_FILENAME=' . self::$keyFile);
        putenv('OCI_SUBNET_ID=ocid1.subnet.oc1..test');
        putenv('OCI_IMAGE_ID=ocid1.image.oc1..test');
    }

    protected function tearDown(): void
    {
        $this->deleteFile();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$keyFile !== null) {
            @unlink(self::$keyFile);
            self::$keyFile = null;
        }
    }

    public function testA1AboveAlwaysFreeLimitNeverLaunches(): void
    {
        $api = $this->getMockBuilder(OciApi::class)->onlyMethods(['call'])->getMock();
        $api->expects($this->never())->method('call');
        $config = $this->getDefaultConfig();
        $config->ocpus = 3;
        $config->memoryInGBs = 12;

        $this->expectException(\InvalidArgumentException::class);
        $api->createInstance($config, 'VM.Standard.A1.Flex', 'ssh key', 'ad');
    }

    public function testCreateInstanceWithoutWaiter(): void
    {
        $mock = $this->getMockBuilder(OciApi::class)
            ->onlyMethods(['call'])
            ->getMock();

        $mock->expects($this->once())
            ->method('call')
            ->willReturn([]);

        $mock->createInstance($this->getDefaultConfig(), 'foo', 'ssh key', 'ad');
    }

    public function testCooldownBlocksEveryCallBeforeSend(): void
    {
        $api = $this->getMockBuilder(OciApi::class)->onlyMethods(['send'])->getMock();
        $api->expects($this->never())->method('send');

        $waiter = new TooManyRequestsWaiter(10);
        $waiter->enable();
        $api->setWaiter($waiter);

        $this->expectException(TooManyRequestsWaiterException::class);
        $this->expectExceptionMessage('Will retry after 10 seconds');
        $api->call($this->getDefaultConfig(), 'https://iaas.us-phoenix-1.oraclecloud.com/20160918/instances/');
    }

    public function testExpiredCooldownAllowsCall(): void
    {
        $api = $this->getMockBuilder(OciApi::class)->onlyMethods(['send'])->getMock();
        $api->expects($this->once())->method('send')->willReturn([]);

        $waiter = new TooManyRequestsWaiter(10);
        file_put_contents($this->getFilename(), time() - 10);
        $api->setWaiter($waiter);

        $api->call($this->getDefaultConfig(), 'https://iaas.us-phoenix-1.oraclecloud.com/20160918/instances/');
        $this->assertFalse($waiter->isTooEarly());
    }

    public function testHttp429EnablesWaiterAndThrows(): void
    {
        $api = $this->getMockBuilder(OciApi::class)->onlyMethods(['send'])->getMock();
        $api->expects($this->once())
            ->method('send')
            ->willThrowException(new ApiCallException('TooManyRequests', 429));

        $waiter = new TooManyRequestsWaiter(10);
        $api->setWaiter($waiter);

        try {
            $api->call($this->getDefaultConfig(), 'https://iaas.us-phoenix-1.oraclecloud.com/20160918/instances/');
            $this->fail('Expected TooManyRequestsWaiterException');
        } catch (TooManyRequestsWaiterException $e) {
            $this->assertSame(429, $e->getCode());
        }

        $this->assertSame(10, $waiter->secondsRemaining());
        $this->assertEquals(time() + 10, (int) file_get_contents($this->getFilename()));
    }

    public function testOtherErrorsDoNotEnableWaiter(): void
    {
        $api = $this->getMockBuilder(OciApi::class)->onlyMethods(['send'])->getMock();
        $api->expects($this->once())
            ->method('send')
            ->willThrowException(new ApiCallException('foo', 500));

        $waiter = new TooManyRequestsWaiter(10);
        $api->setWaiter($waiter);

        $this->expectException(ApiCallException::class);
        try {
            $api->call($this->getDefaultConfig(), 'https://iaas.us-phoenix-1.oraclecloud.com/20160918/instances/');
        } catch (ApiCallException $e) {
            $this->assertFalse($waiter->isTooEarly());
            throw $e;
        }
    }

    private function getFilename(): string
    {
        return sprintf('%s/%s', getcwd(), 'too_many_requests_waiter.txt');
    }

    private function deleteFile(): void
    {
        if (file_exists($this->getFilename())) {
            unlink($this->getFilename());
        }
    }
}
