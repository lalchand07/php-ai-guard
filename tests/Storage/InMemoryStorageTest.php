<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Storage;

use PhpAiGuard\Storage\InMemoryStorage;
use PhpAiGuard\Storage\StorageInterface;

final class InMemoryStorageTest extends StorageContractTestCase
{
    protected function createStorage(): StorageInterface
    {
        return new InMemoryStorage();
    }
}
