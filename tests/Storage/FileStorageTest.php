<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Storage;

use PhpAiGuard\Storage\FileStorage;
use PhpAiGuard\Storage\StorageInterface;

final class FileStorageTest extends StorageContractTestCase
{
    protected function createStorage(): StorageInterface
    {
        return new FileStorage(TempDir::create() . '/store');
    }

    public function testProtectsDirectoryFromWebAccess(): void
    {
        $dir = TempDir::create() . '/store';
        new FileStorage($dir);
        self::assertFileExists($dir . '/.htaccess');
    }
}
