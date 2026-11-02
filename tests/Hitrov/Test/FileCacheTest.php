<?php
declare(strict_types=1);

namespace Hitrov\Test;

use Hitrov\FileCache;
use Hitrov\OciConfig;
use PHPUnit\Framework\TestCase;

class FileCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (file_exists($this->getCacheFilename())) {
            unlink($this->getCacheFilename());
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->getCacheFilename())) {
            unlink($this->getCacheFilename());
        }
    }

    public function testGetCacheKey(): void
    {
        $config = $this->makeConfig();
        $cache = new FileCache($config);

        $this->assertSame(md5(json_encode($config)), $cache->getCacheKey('foo'));
    }

    public function testDifferentConfigsHaveDifferentKeys(): void
    {
        $first = new FileCache($this->makeConfig());
        $second = new FileCache($this->makeConfig('other-subnet'));

        $this->assertNotSame($first->getCacheKey('foo'), $second->getCacheKey('foo'));
    }

    public function testCacheFileCreated(): void
    {
        new FileCache($this->makeConfig());

        $this->assertTrue(file_exists($this->getCacheFilename()));
    }

    public function testAddsCacheFileContents(): void
    {
        $config = $this->makeConfig();
        $cache = new FileCache($config);

        $cache->add([1, 'one'], 'foo');

        $this->assertSame(
            ['foo' => [md5(json_encode($config)) => [1, 'one']]],
            json_decode(file_get_contents($this->getCacheFilename()), true),
        );
    }

    public function testUpdatesCacheFileContents(): void
    {
        $config = $this->makeConfig();
        $cache = new FileCache($config);

        $cache->add([1, 'one'], 'foo');
        $cache->add([2, 'two'], 'foo');

        $this->assertSame(
            ['foo' => [md5(json_encode($config)) => [2, 'two']]],
            json_decode(file_get_contents($this->getCacheFilename()), true),
        );
    }

    public function testUpdatesWithDifferentConfig(): void
    {
        $first = new FileCache($this->makeConfig());
        $second = new FileCache($this->makeConfig('other-subnet'));

        $first->add([1, 'one'], 'foo');
        $second->add([11, 'eleven'], 'foo');

        $contents = json_decode(file_get_contents($this->getCacheFilename()), true);

        $this->assertSame(
            [1, 'one'],
            $contents['foo'][md5(json_encode($this->makeConfig()))],
        );
        $this->assertSame(
            [11, 'eleven'],
            $contents['foo'][md5(json_encode($this->makeConfig('other-subnet')))],
        );
    }

    private function makeConfig(string $subnet = 'subnet'): OciConfig
    {
        return new OciConfig('us-phoenix-1', 'user', 'tenancy', 'fingerprint', 'key.pem', 'jYtI:PHX-AD-1', $subnet, 'image');
    }

    private function getCacheFilename(): string
    {
        return sprintf('%s/%s', getcwd(), 'oci_cache.json');
    }
}
