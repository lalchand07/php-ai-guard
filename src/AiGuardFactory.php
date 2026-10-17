<?php

declare(strict_types=1);

namespace PhpAiGuard;

use PhpAiGuard\Budget\BudgetManager;
use PhpAiGuard\Budget\BudgetPolicy;
use PhpAiGuard\Budget\RateLimiter;
use PhpAiGuard\Budget\TokenEstimator;
use PhpAiGuard\Budget\Windows;
use PhpAiGuard\Cache\ArrayCache;
use PhpAiGuard\Cache\FileCache;
use PhpAiGuard\Cache\NullCache;
use PhpAiGuard\Cache\Psr16Cache;
use PhpAiGuard\Cache\ResponseCacheInterface;
use PhpAiGuard\Clock\ClockInterface;
use PhpAiGuard\Clock\SystemClock;
use PhpAiGuard\Exception\ConfigurationException;
use PhpAiGuard\Http\CurlHttpClient;
use PhpAiGuard\Http\HttpClientInterface;
use PhpAiGuard\Pricing\PricingTable;
use PhpAiGuard\Privacy\PrivacyPolicy;
use PhpAiGuard\Privacy\Redactor;
use PhpAiGuard\Provider\AnthropicProvider;
use PhpAiGuard\Provider\GeminiProvider;
use PhpAiGuard\Provider\JsonMode;
use PhpAiGuard\Provider\OpenAiCompatibleProvider;
use PhpAiGuard\Provider\ProviderInterface;
use PhpAiGuard\Provider\ProviderPresets;
use PhpAiGuard\Retry\RetryPolicy;
use PhpAiGuard\Retry\SleeperInterface;
use PhpAiGuard\Retry\UsleepSleeper;
use PhpAiGuard\Routing\Router;
use PhpAiGuard\Storage\FileStorage;
use PhpAiGuard\Storage\InMemoryStorage;
use PhpAiGuard\Storage\PdoStorage;
use PhpAiGuard\Storage\StorageInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns the config array into a wired AiGuard. Every key is validated so
 * typos fail loudly at start-up instead of silently disabling a limit.
 */
final class AiGuardFactory
{
    private const KEYS = [
        'providers', 'tiers', 'default_tier', 'features', 'pricing', 'unknown_pricing', 'budgets', 'budget_fallback',
        'rate_limits', 'storage', 'cache', 'retry', 'privacy', 'log_prompts', 'log_usage', 'logger', 'http_client',
        'clock', 'sleeper', 'timezone', 'defaults', 'timeout',
    ];

    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config): AiGuard
    {
        $unknown = array_diff(array_keys($config), self::KEYS);
        if ($unknown !== []) {
            throw new ConfigurationException('Unknown config key(s): ' . implode(', ', $unknown) . '. Known keys: ' . implode(', ', self::KEYS) . '.');
        }

        $clock = $config['clock'] ?? new SystemClock();
        if (!$clock instanceof ClockInterface) {
            throw new ConfigurationException('"clock" must implement ClockInterface.');
        }
        $http = $config['http_client'] ?? new CurlHttpClient();
        if (!$http instanceof HttpClientInterface) {
            throw new ConfigurationException('"http_client" must implement PhpAiGuard\Http\HttpClientInterface (wrap PSR-18 clients in Psr18HttpClient).');
        }
        $logger = $config['logger'] ?? null;
        if ($logger !== null && !$logger instanceof LoggerInterface) {
            throw new ConfigurationException('"logger" must be a PSR-3 LoggerInterface.');
        }
        $sleeper = $config['sleeper'] ?? new UsleepSleeper();
        if (!$sleeper instanceof SleeperInterface) {
            throw new ConfigurationException('"sleeper" must implement SleeperInterface.');
        }

        $timezoneName = (string) ($config['timezone'] ?? 'UTC');
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Exception $e) {
            throw new ConfigurationException(sprintf('Invalid timezone "%s".', $timezoneName), 0, $e);
        }

        $providers = self::providers(self::arr($config, 'providers'), $http, (float) ($config['timeout'] ?? 30.0));
        $router = new Router(self::arr($config, 'tiers'), self::arr($config, 'features'), isset($config['default_tier']) ? (string) $config['default_tier'] : null);
        foreach ($router->tiers() as $tier => $targets) {
            foreach ($targets as $target) {
                if (!isset($providers[$target->provider])) {
                    throw new ConfigurationException(sprintf('Tier "%s" uses provider "%s", which is not configured in "providers".', $tier, $target->provider));
                }
            }
        }

        $unknownPricing = $config['unknown_pricing'] ?? 'refuse';
        if (!in_array($unknownPricing, ['refuse', 'allow'], true)) {
            throw new ConfigurationException('"unknown_pricing" must be "refuse" or "allow".');
        }

        $policy = new BudgetPolicy(self::arr($config, 'budgets'));
        $rateLimits = self::arr($config, 'rate_limits');
        $storage = self::storage($config['storage'] ?? null, !$policy->isEmpty() || $rateLimits !== []);

        $defaults = self::arr($config, 'defaults');
        $unknownDefaults = array_diff(array_keys($defaults), ['max_tokens', 'temperature']);
        if ($unknownDefaults !== []) {
            throw new ConfigurationException('Unknown "defaults" key(s): ' . implode(', ', $unknownDefaults) . '. Use max_tokens or temperature.');
        }
        [$cache, $cacheByDefault, $cacheTtl] = self::cache($config['cache'] ?? null, $clock);

        $options = new EngineOptions(
            (int) ($defaults['max_tokens'] ?? 1024),
            isset($defaults['temperature']) ? (float) $defaults['temperature'] : null,
            $unknownPricing === 'allow',
            (bool) ($config['budget_fallback'] ?? false),
            $cacheByDefault,
            $cacheTtl,
            (bool) ($config['log_prompts'] ?? false),
            (bool) ($config['log_usage'] ?? true),
            $timezone->getName()
        );

        $engine = new Engine(
            $providers,
            $router,
            new PricingTable(self::arr($config, 'pricing')),
            new BudgetManager($storage, $policy, $clock, new Windows($timezone)),
            new RateLimiter($storage, $clock, $rateLimits),
            $storage,
            $cache,
            self::privacy(self::arr($config, 'privacy')),
            self::retry(self::arr($config, 'retry')),
            $sleeper,
            $clock,
            new TokenEstimator(),
            $options,
            $logger
        );

        return new AiGuard($engine);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, ProviderInterface>
     */
    public static function providers(array $config, HttpClientInterface $http, float $defaultTimeout = 30.0): array
    {
        if ($config === []) {
            throw new ConfigurationException('Configure at least one provider in "providers".');
        }
        $providers = [];
        foreach ($config as $name => $settings) {
            $name = (string) $name;
            if ($settings instanceof ProviderInterface) {
                $providers[$name] = $settings;
                continue;
            }
            if (!is_array($settings)) {
                throw new ConfigurationException(sprintf('Provider "%s" must be an array of settings or a ProviderInterface.', $name));
            }
            $providers[$name] = self::provider($name, $settings, $http, $defaultTimeout);
        }

        return $providers;
    }

    /**
     * @param array<string, mixed> $s
     */
    private static function provider(string $name, array $s, HttpClientInterface $http, float $defaultTimeout): ProviderInterface
    {
        $known = ['type', 'api_key', 'base_url', 'timeout', 'connect_timeout', 'allows_personal_data', 'json_mode', 'headers', 'prompt_cache', 'anthropic_version', 'max_tokens_param', 'legacy_json_fields'];
        $unknown = array_diff(array_keys($s), $known);
        if ($unknown !== []) {
            throw new ConfigurationException(sprintf('Provider "%s": unknown setting(s) %s. Known: %s.', $name, implode(', ', $unknown), implode(', ', $known)));
        }

        $type = (string) ($s['type'] ?? $name);
        $apiKey = (string) ($s['api_key'] ?? '');
        $timeout = (float) ($s['timeout'] ?? $defaultTimeout);
        $connectTimeout = (float) ($s['connect_timeout'] ?? min(10.0, $timeout));
        $allows = (bool) ($s['allows_personal_data'] ?? true);
        $headers = [];
        foreach ((array) ($s['headers'] ?? []) as $header => $value) {
            $headers[(string) $header] = (string) $value;
        }

        if ($type === 'anthropic') {
            return new AnthropicProvider(
                $http,
                $apiKey,
                $name,
                (string) ($s['base_url'] ?? AnthropicProvider::DEFAULT_BASE_URL),
                $timeout,
                $allows,
                (string) ($s['json_mode'] ?? JsonMode::PROMPT),
                $headers,
                is_string($s['prompt_cache'] ?? null) ? (string) $s['prompt_cache'] : (bool) ($s['prompt_cache'] ?? false),
                (string) ($s['anthropic_version'] ?? AnthropicProvider::API_VERSION),
                $connectTimeout
            );
        }
        if ($type === 'gemini' || $type === 'google') {
            return new GeminiProvider(
                $http,
                $apiKey,
                $name,
                (string) ($s['base_url'] ?? GeminiProvider::DEFAULT_BASE_URL),
                $timeout,
                $allows,
                (string) ($s['json_mode'] ?? JsonMode::NATIVE),
                $headers,
                (bool) ($s['legacy_json_fields'] ?? false),
                $connectTimeout
            );
        }

        $preset = ProviderPresets::get($type);
        if ($preset === null && $type !== 'openai-compatible') {
            throw new ConfigurationException(sprintf(
                'Provider "%s": unknown type "%s". Use anthropic, gemini, openai, groq, openrouter, nvidia, ollama or openai-compatible.',
                $name,
                $type
            ));
        }
        if ($apiKey === '' && ($preset['requires_key'] ?? false)) {
            throw new ConfigurationException(sprintf('Provider "%s": "api_key" is required (is the environment variable set?).', $name));
        }
        $baseUrl = (string) ($s['base_url'] ?? ($preset['base_url'] ?? ''));

        return new OpenAiCompatibleProvider(
            $http,
            $apiKey,
            $baseUrl,
            $name,
            $timeout,
            $allows,
            (string) ($s['json_mode'] ?? ($preset['json_mode'] ?? JsonMode::OBJECT)),
            $headers,
            (string) ($s['max_tokens_param'] ?? ($preset['max_tokens_param'] ?? 'max_tokens')),
            $connectTimeout
        );
    }

    /**
     * Builds only the storage from a "storage" config value (used by the CLI and dashboard).
     */
    public static function storage(mixed $config, bool $needed = false): StorageInterface
    {
        if ($config instanceof StorageInterface) {
            return $config;
        }
        if ($config instanceof \PDO) {
            return new PdoStorage($config);
        }
        if ($config === null) {
            if ($needed) {
                throw new ConfigurationException('Budgets and rate limits need persistent storage: set "storage" (e.g. [\'driver\' => \'sqlite\', \'path\' => __DIR__ . \'/var/ai-guard.sqlite\']).');
            }

            return new InMemoryStorage();
        }
        if (!is_array($config)) {
            throw new ConfigurationException('"storage" must be an array, a PDO or a StorageInterface.');
        }

        $driver = (string) ($config['driver'] ?? '');
        $prefix = (string) ($config['table_prefix'] ?? 'ai_guard_');
        switch ($driver) {
            case 'memory':
                return new InMemoryStorage();
            case 'file':
                return new FileStorage(self::requireString($config, 'path', 'storage'));
            case 'sqlite':
                return PdoStorage::sqlite(self::requireString($config, 'path', 'storage'), $prefix, (bool) ($config['auto_install'] ?? true));
            case 'mysql':
            case 'mariadb':
                $dsn = isset($config['dsn']) ? (string) $config['dsn'] : sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    (string) ($config['host'] ?? 'localhost'),
                    (int) ($config['port'] ?? 3306),
                    self::requireString($config, 'database', 'storage')
                );
                $storage = PdoStorage::mysql($dsn, isset($config['username']) ? (string) $config['username'] : null, isset($config['password']) ? (string) $config['password'] : null, $prefix);
                if ((bool) ($config['auto_install'] ?? false)) {
                    $storage->install();
                }

                return $storage;
            case 'pdo':
                if (!($config['pdo'] ?? null) instanceof \PDO) {
                    throw new ConfigurationException('storage driver "pdo" needs a "pdo" => PDO instance.');
                }

                return new PdoStorage($config['pdo'], $prefix);
            default:
                throw new ConfigurationException(sprintf('Unknown storage driver "%s". Use sqlite, mysql, file, memory or pdo.', $driver));
        }
    }

    /**
     * @return array{0: ResponseCacheInterface, 1: bool, 2: int}
     */
    private static function cache(mixed $config, ClockInterface $clock): array
    {
        if ($config === null || $config === false) {
            return [new NullCache(), false, 3600];
        }
        if ($config instanceof ResponseCacheInterface) {
            return [$config, true, 3600];
        }
        if (interface_exists(\Psr\SimpleCache\CacheInterface::class) && $config instanceof \Psr\SimpleCache\CacheInterface) {
            return [new Psr16Cache($config), true, 3600];
        }
        if (!is_array($config)) {
            throw new ConfigurationException('"cache" must be false, an array, a ResponseCacheInterface or a PSR-16 cache.');
        }

        $ttl = (int) ($config['ttl'] ?? 3600);
        $default = (bool) ($config['default'] ?? true);
        $store = $config['store'] ?? null;
        if ($store instanceof ResponseCacheInterface) {
            return [$store, $default, $ttl];
        }
        if (interface_exists(\Psr\SimpleCache\CacheInterface::class) && $store instanceof \Psr\SimpleCache\CacheInterface) {
            return [new Psr16Cache($store), $default, $ttl];
        }
        $driver = (string) ($config['driver'] ?? 'file');

        return match ($driver) {
            'file' => [new FileCache(self::requireString($config, 'path', 'cache'), $clock), $default, $ttl],
            'memory', 'array' => [new ArrayCache($clock), $default, $ttl],
            default => throw new ConfigurationException(sprintf('Unknown cache driver "%s". Use file or memory, or pass "store" => PSR-16 cache.', $driver)),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function privacy(array $config): PrivacyPolicy
    {
        $known = ['redact', 'restore', 'patterns', 'custom', 'on_disallowed'];
        $unknown = array_diff(array_keys($config), $known);
        if ($unknown !== []) {
            throw new ConfigurationException('Unknown "privacy" key(s): ' . implode(', ', $unknown) . '. Known: ' . implode(', ', $known) . '.');
        }
        /** @var list<string> $patterns */
        $patterns = array_values(array_map('strval', (array) ($config['patterns'] ?? Redactor::BUILT_IN)));
        /** @var array<string, string> $custom */
        $custom = (array) ($config['custom'] ?? []);

        return new PrivacyPolicy(
            Redactor::create($patterns, $custom),
            (bool) ($config['redact'] ?? false),
            (bool) ($config['restore'] ?? true),
            (string) ($config['on_disallowed'] ?? PrivacyPolicy::REDACT)
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function retry(array $config): RetryPolicy
    {
        return new RetryPolicy(
            (int) ($config['max_retries'] ?? 2),
            (int) ($config['base_delay_ms'] ?? 500),
            (int) ($config['max_delay_ms'] ?? 8000),
            (float) ($config['max_retry_after'] ?? 30.0)
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<mixed>
     */
    private static function arr(array $config, string $key): array
    {
        $value = $config[$key] ?? [];
        if (!is_array($value)) {
            throw new ConfigurationException(sprintf('"%s" must be an array.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requireString(array $config, string $key, string $section): string
    {
        if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
            throw new ConfigurationException(sprintf('"%s.%s" is required.', $section, $key));
        }

        return $config[$key];
    }
}
