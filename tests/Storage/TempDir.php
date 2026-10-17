<?php

declare(strict_types=1);

namespace PhpAiGuard\Tests\Storage;

final class TempDir
{
    public static function create(string $prefix = 'ai-guard-test-'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        register_shutdown_function(static fn () => self::remove($dir));

        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
