<?php

declare(strict_types=1);

namespace PhpAiGuard\Cli;

use PhpAiGuard\AiGuardFactory;
use PhpAiGuard\Exception\AiGuardException;
use PhpAiGuard\Report\Reporter;
use PhpAiGuard\Report\TableRenderer;
use PhpAiGuard\Storage\PdoStorage;
use PhpAiGuard\Storage\StorageInterface;

/**
 * vendor/bin/ai-guard
 *
 *   report  [--month=2026-10] [--by=tenant|user|feature|model|provider|day|status] [--csv] [--config=ai-guard.php]
 *   install [--config=ai-guard.php]     create the database tables
 *   prune   [--config=ai-guard.php]     delete expired budget/rate-limit counters
 */
final class Application
{
    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct($out = null, $err = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        array_shift($argv);
        $command = $argv[0] ?? 'help';
        if (str_starts_with($command, '-')) {
            $command = 'help';
        }
        $options = self::parseOptions(array_slice($argv, 1));

        try {
            switch ($command) {
                case 'report':
                    return $this->report($options);
                case 'install':
                    return $this->install($options);
                case 'prune':
                    $removed = $this->storage($options)->prune(time());
                    $this->write(sprintf("Removed %d expired counter(s).\n", $removed));

                    return 0;
                case 'help':
                case '--help':
                    $this->write(self::help());

                    return 0;
                default:
                    $this->error(sprintf("Unknown command \"%s\".\n\n%s", $command, self::help()));

                    return 2;
            }
        } catch (AiGuardException | \InvalidArgumentException $e) {
            $this->error('Error: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function report(array $options): int
    {
        $config = $this->loadConfig($options);
        $month = is_string($options['month'] ?? null) ? (string) $options['month'] : (new \DateTimeImmutable('now', new \DateTimeZone((string) ($config['timezone'] ?? 'UTC'))))->format('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new \InvalidArgumentException('--month must look like 2026-10.');
        }
        $by = is_string($options['by'] ?? null) ? (string) $options['by'] : 'model';

        $reporter = new Reporter(AiGuardFactory::storage($config['storage'] ?? null, true), (string) ($config['timezone'] ?? 'UTC'));
        $rows = $reporter->month($month, $by);

        if (isset($options['csv'])) {
            $this->write(TableRenderer::csv($rows, $by));

            return 0;
        }
        $this->write(sprintf("AI usage for %s by %s\n", $month, $by));
        if ($rows === []) {
            $this->write("No usage recorded.\n");

            return 0;
        }
        $this->write(TableRenderer::text($rows, ucfirst($by)));

        return 0;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function install(array $options): int
    {
        $storage = $this->storage($options);
        if (!$storage instanceof PdoStorage) {
            $this->write("This storage needs no installation.\n");

            return 0;
        }
        $storage->install();
        $this->write("Tables created (or already present).\n");

        return 0;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function storage(array $options): StorageInterface
    {
        $config = $this->loadConfig($options);

        return AiGuardFactory::storage($config['storage'] ?? null, true);
    }

    /**
     * @param array<string, string|bool> $options
     *
     * @return array<string, mixed>
     */
    private function loadConfig(array $options): array
    {
        $candidates = [];
        if (is_string($options['config'] ?? null)) {
            $candidates[] = (string) $options['config'];
        } else {
            $env = getenv('AI_GUARD_CONFIG');
            if (is_string($env) && $env !== '') {
                $candidates[] = $env;
            }
            $candidates[] = getcwd() . '/ai-guard.php';
            $candidates[] = getcwd() . '/config/ai-guard.php';
        }
        foreach ($candidates as $file) {
            if (is_file($file)) {
                $config = require $file;
                if (!is_array($config)) {
                    throw new \InvalidArgumentException(sprintf('%s must return the php-ai-guard config array.', $file));
                }

                /** @var array<string, mixed> $config */
                return $config;
            }
        }

        throw new \InvalidArgumentException('No config found. Pass --config=path/to/ai-guard.php (a PHP file returning the config array) or set AI_GUARD_CONFIG.');
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, string|bool>
     */
    private static function parseOptions(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $parts = explode('=', substr($arg, 2), 2);
            $options[$parts[0]] = $parts[1] ?? true;
        }

        return $options;
    }

    private static function help(): string
    {
        return <<<TXT
            php-ai-guard CLI

            Usage:
              ai-guard report [--month=YYYY-MM] [--by=tenant|user|feature|model|provider|day|status] [--csv] [--config=FILE]
              ai-guard install [--config=FILE]   Create the database tables (MySQL/MariaDB/SQLite)
              ai-guard prune [--config=FILE]     Delete expired budget and rate-limit counters

            The config file is a PHP file returning the same array you pass to AiGuard::create().
            Default: \$AI_GUARD_CONFIG, ./ai-guard.php or ./config/ai-guard.php

            TXT;
    }

    private function write(string $text): void
    {
        fwrite($this->out, $text);
    }

    private function error(string $text): void
    {
        fwrite($this->err, $text);
    }
}
