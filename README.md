# php-ai-guard

**Call Claude, Gemini or any OpenAI-compatible LLM from plain PHP, even on cheap shared hosting, with hard spending limits, per-user usage logs, caching, fallbacks and privacy redaction built in.**

[![CI](https://github.com/your-vendor/php-ai-guard/actions/workflows/ci.yml/badge.svg)](https://github.com/your-vendor/php-ai-guard/actions/workflows/ci.yml)
![PHP 8.0–8.4](https://img.shields.io/badge/php-8.0%20%E2%80%93%208.4-777bb4)
![License: MIT](https://img.shields.io/badge/license-MIT-green)

Most AI tooling assumes Python, Node or a big framework. php-ai-guard is for the
millions of plain PHP apps (a booking site, a CRM, a WordPress-era custom
system on cPanel) that want to add one AI feature **without** risking a
surprise bill, leaking customer data or going down when a provider is
overloaded.

- **One API** for Anthropic (Claude), Google Gemini and any OpenAI-compatible endpoint (OpenAI, Groq, OpenRouter, NVIDIA NIM, Ollama, vLLM, LM Studio).
- **Hard budgets** in USD, global, per tenant (company) and per user, daily and monthly. A call is refused **before it is sent** if its worst-case cost could exceed what is left.
- **Rate limits** per user and tenant to stop abuse and "cost exhaustion" attacks.
- **Usage log** of every call: tenant, user, feature, provider, model, tokens, cost, latency, cache hit and status. Reports from the CLI or a one-file HTML dashboard.
- **Fallback chains** with retries, exponential backoff, jitter and `Retry-After`. Never fails over on a 4xx validation error.
- **Response cache**, so identical requests are paid for once.
- **Structured JSON** validated against a schema, with one automatic corrective retry.
- **Privacy**: redact emails, phone numbers, card numbers (Luhn-checked), IBANs and passport MRZ lines before they leave your server, then restore them in the answer.
- Requires only **PHP 8.0+, ext-curl and ext-json**. Storage: SQLite, MySQL/MariaDB or plain files. No framework, no daemon, no Redis.

---

## Contents

- [Quick start](#quick-start)
- [Add AI to a cPanel PHP app](#add-ai-to-a-cpanel-php-app)
- [Budgets explained](#budgets-explained)
- [Rate limits](#rate-limits)
- [Providers](#providers)
- [Model routing and fallbacks](#model-routing-and-fallbacks)
- [Structured JSON output](#structured-json-output)
- [Privacy](#privacy)
- [Caching](#caching)
- [Storage](#storage)
- [Reporting](#reporting)
- [Errors](#errors)
- [Testing your own code](#testing-your-own-code)
- [FAQ](#faq)
- [Roadmap](#roadmap)

---

## Quick start

```bash
composer require your-vendor/php-ai-guard
```

```php
<?php
require 'vendor/autoload.php';

$ai = PhpAiGuard\AiGuard::create([
    'providers' => ['anthropic' => ['api_key' => getenv('ANTHROPIC_API_KEY')], 'gemini' => ['api_key' => getenv('GEMINI_API_KEY')]],
    'tiers'     => ['standard' => ['anthropic:claude-haiku-5-5', 'gemini:gemini-3.8-flash']], // fallback order
    'pricing'   => require 'vendor/your-vendor/php-ai-guard/config/pricing.example.php',     // verify these!
    'budgets'   => ['global' => ['monthly' => 20.00], 'user' => ['monthly' => 1.00]],
    'storage'   => ['driver' => 'sqlite', 'path' => __DIR__ . '/../var/ai-guard.sqlite'],
]);

echo $ai->forUser('42')->chat('Write a haiku about PHP')->text;
```

That call:

1. estimates its worst-case cost and **refuses** (with `BudgetExceededException`) if user 42 or the whole app could go over budget,
2. calls Claude Haiku, and on a timeout, 429 or 5xx retries with backoff and then **falls back** to Gemini,
3. records the real tokens and cost against user 42 in SQLite.

Try it without an API key: `php examples/01-basic.php` runs in demo mode.

---

## Add AI to a cPanel PHP app

You do not need SSH, Docker or a VPS.

1. **Install the package.** If your host has a terminal (most cPanel hosts do: *Advanced → Terminal*), run `composer require your-vendor/php-ai-guard` in your project folder. No Composer on the server? Run it on your computer and upload the `vendor/` folder with the File Manager or FTP.
2. **Make a private folder** next to (not inside) `public_html`, e.g. `/home/youruser/app-data/`. The SQLite database, cache and config go there. php-ai-guard also drops a deny-all `.htaccess` into its storage and cache folders, but outside `public_html` is safer.
3. **Store your API key.** Best: put it in a PHP file outside `public_html` (`/home/youruser/app-data/secrets.php` returning an array) or set it in *cPanel → Environment Variables* / `SetEnv ANTHROPIC_API_KEY ...` in an `.htaccess` outside the web root. Never commit keys to Git.
4. **Check PHP.** In *Select PHP Version*, choose 8.0 or newer and tick `curl`, `pdo_sqlite` (or `pdo_mysql`) and, ideally, `mbstring`.
5. **Create the config** `/home/youruser/app-data/ai-guard.php` from [`config/ai-guard.example.php`](config/ai-guard.example.php) and set your budgets.
6. **Use it** in your page:

   ```php
   require '/home/youruser/public_html/vendor/autoload.php';
   $ai = PhpAiGuard\AiGuard::create(require '/home/youruser/app-data/ai-guard.php');

   try {
       $answer = $ai->forUser((string) $_SESSION['user_id'])->chat($_POST['question'], ['max_tokens' => 300])->text;
   } catch (PhpAiGuard\Exception\BudgetExceededException | PhpAiGuard\Exception\RateLimitExceededException $e) {
       $answer = $e->userMessage();                    // "You have reached your AI usage limit for today…"
   } catch (PhpAiGuard\Exception\AiGuardException $e) {
       error_log($e->getMessage());
       $answer = 'The assistant is unavailable right now.';
   }
   echo htmlspecialchars($answer);
   ```

7. **Want MySQL instead of SQLite?** Create a database in *MySQL Databases*, use `'storage' => ['driver' => 'mysql', 'host' => 'localhost', 'database' => '...', 'username' => '...', 'password' => '...']`, and run `vendor/bin/ai-guard install --config=/home/youruser/app-data/ai-guard.php` once (or import [`sql/mysql.sql`](sql/mysql.sql) in phpMyAdmin).
8. **See what you spend:** `vendor/bin/ai-guard report --by=user`, or include the [dashboard](#html-dashboard) on an admin-only page.

Shared hosts often cap `max_execution_time` at 30 s. Keep `timeout` (default 30 s) and `max_retries` modest so retries plus fallbacks finish in time, or raise the limit for AI pages with `set_time_limit(90)`.

A complete form with CSRF protection is in [`examples/web-form/index.php`](examples/web-form/index.php).

---

## Budgets explained

Budgets are the core feature. You set limits in **USD** per **scope** and **window**:

| Scope | Applies to | Example |
|---|---|---|
| `global` | the whole application | `'global' => ['daily' => 5, 'monthly' => 100]` |
| `tenant` | each tenant (company, team, site), set with `forUser($user, $tenant)` / `forTenant()` | `'tenant' => ['monthly' => 20]` |
| `user` | each end user | `'user' => ['daily' => 0.25, 'monthly' => 2]` |
| `tenants` / `users` | overrides for specific ids | `'users' => ['admin-1' => ['daily' => 5]]` |

Windows are the **calendar day** and **calendar month** in your configured `timezone` (default UTC), so a monthly budget resets at midnight on the 1st.

### How a call is checked

Before anything is sent, php-ai-guard computes the **worst case**:

```
estimated input tokens  = pessimistic count of system + messages (+ schema)
worst-case cost         = input tokens × input price  +  max_tokens × output price
```

and **reserves** that amount atomically on every applicable counter (global, tenant, user × daily, monthly). If any counter would go over its limit, nothing is reserved, nothing is sent, and you get a `BudgetExceededException`. After the call, the reservation is replaced by the **real** cost reported by the provider, so you are only charged for what was used.

**Worked example.** Your config:

```php
'pricing' => ['anthropic' => ['claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50]]], // USD per 1M tokens
'budgets' => ['user' => ['monthly' => 2.00]],
```

A user sends a 3,000-character prompt with `max_tokens => 500`:

| | Tokens | Price / 1M | Cost |
|---|---|---|---|
| Input (estimated, pessimistic) | 1,024 | $0.10 | $0.0001024 |
| Output (worst case = max_tokens) | 500 | $0.50 | $0.000250 |
| **Reserved before the call** | | | **$0.0003524** |
| Actually used (e.g. 760 in / 210 out) | | | $0.000181 |

If that user has already spent $1.99990, the call is refused ($1.99990 + $0.00035 > $2.00) and they see *"You have reached your AI usage limit for this month. It resets on 1 November 2026."* (`$e->userMessage()`). The developer message (`$e->getMessage()`) has the numbers. If they had $1.99960 spent, the call goes ahead, and afterwards their counter shows $1.99978.

**Concurrency-safe.** The check-and-reserve is one conditional `UPDATE … WHERE amount + :cost <= :limit` per counter inside a single transaction (SQLite `BEGIN IMMEDIATE`; MySQL row locks taken in sorted order). Twenty simultaneous requests can never jointly overspend. The test suite proves it with several real PHP processes against SQLite, MariaDB and file storage.

**Keep `max_tokens` realistic.** It is your guaranteed upper bound on output cost. The default is 1024.

**Show users what is left:**

```php
$left = $ai->forUser('42')->remainingBudget('user', null, 'monthly'); // float USD, or null if unlimited
```

**Unknown models are refused.** If a model has no row in your pricing table, php-ai-guard cannot check it against your budget, so it throws `PricingUnknownException`. Set `'unknown_pricing' => 'allow'` to permit it (its cost is then logged as $0).

**Cheaper fallback when over budget.** With `'budget_fallback' => true`, a call that would exceed the budget on the first model in a tier tries the next (cheaper) one instead of failing immediately.

> **Honest limits.** The input-token estimate is deliberately pessimistic (≈3 bytes per token, more for non-Latin scripts) but it is still an estimate. A provider's tokenizer could count more in unusual cases, so a budget can be overshot by at most that difference on the final call. Output can never exceed `max_tokens`. Prices are only as accurate as your pricing table.

---

## Rate limits

```php
'rate_limits' => ['user' => 10, 'tenant' => 60, 'global' => 300], // requests per minute
```

Checked before routing, caching or budgets. A refused request is not counted and is logged with status `rate_limited`, so a script hammering your AI endpoint shows up in the reports. `RateLimitExceededException::getRetryAfterSeconds()` tells you when to try again.

---

## Providers

Each provider's integration was written from its official API reference (read 2026-10-17). Responses recorded from those docs are used in the tests.

| Provider | Config | Default base URL | Auth | Notes |
|---|---|---|---|---|
| **Anthropic** | `'anthropic' => ['api_key' => …]` | `https://api.anthropic.com` | `x-api-key` | Messages API, `anthropic-version: 2023-06-01`. Optional prompt caching (`'prompt_cache' => true` or `'1h'`). `json_mode`: `prompt` (default) or `native` (structured outputs on supported models). |
| **Google Gemini** | `'gemini' => ['api_key' => …]` | `https://generativelanguage.googleapis.com/v1beta` | `x-goog-api-key` header (never in the URL) | `generateContent`. Thinking tokens are billed as output. `json_mode`: `native` (default, `responseFormat`), `object`, `prompt`; `'legacy_json_fields' => true` uses `responseMimeType`/`responseJsonSchema`. |
| **OpenAI** | `'openai' => ['api_key' => …]` | `https://api.openai.com/v1` | Bearer | Chat Completions, `max_completion_tokens`, JSON schema mode. |
| **Groq** | `'groq' => ['api_key' => …]` | `https://api.groq.com/openai/v1` | Bearer | `max_completion_tokens`, `json_object`. |
| **OpenRouter** | `'openrouter' => ['api_key' => …, 'headers' => ['HTTP-Referer' => …, 'X-OpenRouter-Title' => …]]` | `https://openrouter.ai/api/v1` | Bearer | `max_tokens`, `json_object`. Gateway errors returned with HTTP 200 are detected and retried. |
| **NVIDIA NIM** | `'nvidia' => ['api_key' => …]` | `https://integrate.api.nvidia.com/v1` | Bearer | OpenAI-compatible. JSON mode defaults to `prompt` (see note). |
| **Ollama** (local) | `'local' => ['type' => 'ollama']` | `http://localhost:11434/v1` | none | `max_tokens`, `json_object`. Price it as `'ollama' => ['*' => ['free' => true]]`. |
| **Anything OpenAI-compatible** | `'x' => ['type' => 'openai-compatible', 'base_url' => …, 'api_key' => …]` | | Bearer (if a key is set) | `max_tokens_param`, `json_mode`, `headers` are configurable. |

Common provider settings: `timeout` (seconds, default 30), `connect_timeout`, `allows_personal_data`, `json_mode`, `headers`, `base_url`.

> **NVIDIA note:** NVIDIA's own API docs could not be reached when this library was written, so NIM support relies only on NVIDIA's statement that its hosted endpoints are OpenAI-compatible. Nothing NIM-specific is assumed. Please report anything that does not work.

Any other backend can be added by implementing `PhpAiGuard\Provider\ProviderInterface` (four methods) and passing the object instead of a settings array.

---

## Model routing and fallbacks

Name your tiers and list targets as `provider:model` in fallback order:

```php
'tiers' => [
    'cheap'    => ['groq:openai/gpt-oss-20b', 'gemini:gemini-3.5-flash-lite'],
    'standard' => ['anthropic:claude-haiku-5-5', 'gemini:gemini-3.8-flash'],
    'smart'    => ['anthropic:claude-sonnet-5-5', 'gemini:gemini-3.8-flash'],
],
'default_tier' => 'standard',
'features' => ['classify' => 'cheap', 'draft' => 'standard'],
```

```php
$ai->chat($text, ['tier' => 'cheap']);                      // by tier
$ai->withFeature('classify')->chat($text);                  // by feature → tier (also tags reports)
$ai->chat($text, ['model' => 'anthropic:claude-opus-5-5']); // one exact model, no fallback
```

**What triggers a fallback:** timeouts, network errors, HTTP 408, 429, 5xx and Anthropic's 529 "overloaded". Each target is first retried `max_retries` times (default 2) with exponential backoff and full jitter (`base_delay_ms` 500, `max_delay_ms` 8000). A `Retry-After` header is honoured; if it asks for longer than `max_retry_after` (30 s) we move to the next target immediately instead of sleeping.

**What never triggers a fallback:** 400/404/413/422 (bad request: another provider would reject it too), 401/403 (bad key), and safety blocks. These throw immediately.

### Free tiers for development, paid for production

```php
$isProduction = getenv('APP_ENV') === 'production';

$config['providers'] = [
    'groq'      => ['api_key' => getenv('GROQ_API_KEY'),   'allows_personal_data' => false], // free tier
    'gemini'    => ['api_key' => getenv('GEMINI_API_KEY'), 'allows_personal_data' => false], // free tier
    'anthropic' => ['api_key' => getenv('ANTHROPIC_API_KEY') ?: 'unused-in-dev'],
    'local'     => ['type' => 'ollama'],
];
$config['tiers'] = $isProduction
    ? ['standard' => ['anthropic:claude-haiku-5-5', 'gemini:gemini-3.8-flash']]          // paid, with fallback
    : ['standard' => ['groq:openai/gpt-oss-20b', 'gemini:gemini-3.8-flash', 'local:llama3.2:3b']];
```

In development every call hits free tiers (and finally your laptop's Ollama). Because they are marked `allows_personal_data => false`, real customer data you test with is redacted before it is sent.

---

## Structured JSON output

```php
$schema = [
    'type' => 'object',
    'required' => ['sentiment', 'confidence'],
    'properties' => [
        'sentiment'  => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
    ],
];

$result = $ai->json('Review: "Fast delivery, great support!"', $schema);
// ['sentiment' => 'positive', 'confidence' => 0.93], validated

$response = $ai->jsonResponse($prompt, $schema); // same, plus cost/tokens in $response
```

Supported schema keywords: `type` (incl. `["string","null"]`), `properties`, `required`, `additionalProperties`, `items`, `minItems`, `maxItems`, `enum`, `const`, `minLength`, `maxLength`, `pattern`, `minimum`, `maximum`, `format` (`date`, `date-time`, `time`, `email`). Nested objects and arrays work.

If the answer is not valid JSON or breaks the schema, the model is shown its answer and the list of errors (`$.legs[0].date: is not a valid date`) and asked **once** to correct it. If it is still wrong you get a `JsonValidationException` with `getErrors()` and `getRawText()`. Both attempts are budgeted and logged.

The schema is always described in the prompt and always validated locally. Where a provider supports it, the provider's own JSON mode is used too (`json_mode` per provider: `native`, `object` or `prompt`).

See [`examples/03-travel-whatsapp.php`](examples/03-travel-whatsapp.php): it turns a pasted WhatsApp chat into passengers, routes and dates.

---

## Privacy

```php
'providers' => [
    'gemini' => ['api_key' => '…', 'allows_personal_data' => false], // free tier: may log prompts
],
'privacy' => [
    'redact'        => false,                  // true = redact for every provider
    'restore'       => true,                   // put the real values back into the answer
    'patterns'      => ['mrz', 'iban', 'card', 'email', 'phone'],
    'custom'        => ['EMPLOYEE_ID' => '/\bEMP-\d{6}\b/'],
    'on_disallowed' => 'redact',               // or 'skip': never send PII to that provider at all
],
```

- Built-in detectors: **emails**; **phone numbers** (international and national, ignoring dates and short numbers); **card numbers** (13–19 digits, Luhn-checked); **IBANs** (mod-97 checked); **passport/ID MRZ lines** (ICAO 9303 TD3 and TD1, check digits verified); plus your own regexes.
- `Contact jane@example.com or +44 20 7946 0958` is sent as `Contact [EMAIL_1] or [PHONE_1]`. If the model answers *"I'll email [EMAIL_1]"*, your user sees *"I'll email jane@example.com"*. Restoration also works inside `json()` results.
- For providers with `allows_personal_data => false`, requests are **always** redacted (`on_disallowed => 'redact'`) or that provider is **skipped** in the fallback chain when personal data is detected (`'skip'`).
- The placeholder map lives only in memory for the duration of the call. Caches and logs only ever see the redacted text.
- **Logging:** API keys are never logged, never put in URLs (Gemini's key goes in a header) and are hidden from `var_dump()` of requests. Prompts and answers are **not** stored unless you set `'log_prompts' => true`, and even then the redacted version is stored.

Pattern-based redaction is a safety net, not a guarantee: it will not catch names, addresses or free-text health data. Combine it with sensible provider choices.

---

## Caching

```php
'cache' => ['driver' => 'file', 'path' => __DIR__ . '/../var/ai-cache', 'ttl' => 86400],
// or 'cache' => ['store' => $anyPsr16Cache, 'ttl' => 3600],
```

The key is a SHA-256 hash of provider, model, system prompt, messages, `max_tokens`, temperature and schema, taken **after** redaction. Identical requests from different users share one answer and cost $0 (logged with `cache_hit = 1`). Turn it off per call with `['cache' => false]`, or set `'default' => false` to cache only calls that pass `['cache' => true]`.

---

## Storage

| Driver | Use for | Config |
|---|---|---|
| SQLite | most small and medium sites | `['driver' => 'sqlite', 'path' => '/private/ai.sqlite']` (tables are created automatically) |
| MySQL / MariaDB | existing database, several web servers | `['driver' => 'mysql', 'host' => …, 'database' => …, 'username' => …, 'password' => …, 'table_prefix' => 'ai_guard_']` |
| `PDO` object | reuse your connection | `'storage' => $pdo` |
| Files | tiny sites without PDO | `['driver' => 'file', 'path' => '/private/ai-guard']` |
| Memory | tests only | `['driver' => 'memory']` |

Budgets and rate limits require persistent storage: configuring them with no `storage` is an error, never a silent no-op. The schema is in [`sql/`](sql) and `vendor/bin/ai-guard install` creates it. `vendor/bin/ai-guard prune` deletes expired counters (run it from a monthly cron if you like). Anything else can be plugged in by implementing `PhpAiGuard\Storage\StorageInterface`.

---

## Reporting

### CLI

```
$ vendor/bin/ai-guard report --month=2026-10 --by=tenant --config=ai-guard.php
AI usage for 2026-10 by tenant
+---------+----------+-----------+------------+------------+------------+--------+--------+-----------+
| Tenant  | Requests | Input tok | Output tok | Cached tok | Cache hits | Errors | Avg ms |  Cost USD |
+---------+----------+-----------+------------+------------+------------+--------+--------+-----------+
| acme    |    1,284 | 1,932,114 |    402,877 |    310,500 |        212 |      3 |    840 |     $4.02 |
| globex  |      311 |   402,530 |     88,102 |          0 |         40 |      0 |    790 |   $0.8429 |
+---------+----------+-----------+------------+------------+------------+--------+--------+-----------+
| TOTAL   |    1,595 | 2,334,644 |    490,979 |            |        252 |      3 |        |     $4.86 |
+---------+----------+-----------+------------+------------+------------+--------+--------+-----------+
```

`--by=tenant|user|feature|model|provider|day|status`, `--csv` for spreadsheets (formula injection is neutralised). The config file is the same PHP file you pass to `AiGuard::create()` (only `storage` and `timezone` are read). Default lookup: `--config`, `$AI_GUARD_CONFIG`, `./ai-guard.php`, `./config/ai-guard.php`.

From code: `$ai->reporter()->month('2026-10', 'user')` returns the rows as arrays.

### HTML dashboard

A single self-contained file (no JS, no CDN, light and dark mode) with month navigation, grouping tabs, totals and a cost bar per row. It has **no login of its own**; include it from a page you have already protected:

```php
<?php // admin/ai-usage.php
require __DIR__ . '/../vendor/autoload.php';
require_admin();                                      // your own auth check
$aiGuard = PhpAiGuard\AiGuard::create(require '/home/youruser/app-data/ai-guard.php');
require __DIR__ . '/../vendor/your-vendor/php-ai-guard/dashboard/dashboard.php';
```

---

## Errors

Everything extends `PhpAiGuard\Exception\AiGuardException`.

| Exception | When | Retry/fallback? |
|---|---|---|
| `BudgetExceededException` | worst-case cost could exceed a budget (nothing sent). `userMessage()`, `getRemaining()`, `getResetsAt()` | no |
| `RateLimitExceededException` | too many requests per minute. `userMessage()`, `getRetryAfterSeconds()` | no |
| `PricingUnknownException` | model has no price and `unknown_pricing` is `refuse` | no |
| `PrivacyException` | personal data and no allowed provider (`on_disallowed = skip`) | no |
| `JsonValidationException` | invalid JSON after the corrective retry. `getErrors()`, `getRawText()` | |
| `AllProvidersFailedException` | every target failed with retryable errors. `getAttempts()` | after the whole chain |
| `ProviderTimeoutException`, `ProviderNetworkException`, `ProviderRateLimitException`, `ProviderUnavailableException` | transient provider failures (subclasses of `TransientProviderException`) | yes |
| `InvalidRequestException`, `AuthenticationException`, `ContentBlockedException` | 4xx / bad key / safety block | **never** |
| `ConfigurationException` | invalid config: typos fail loudly at start-up | |
| `StorageException` | database or file problem | |

`Response` gives you `text`, `json`, `usage` (`inputTokens`, `outputTokens`, `cacheReadTokens`, `cacheWriteTokens`), `cost`, `latencyMs`, `provider`, `model`, `cacheHit`, `finishReason`, `isTruncated()`, `attempts` and the provider's `raw` body.

---

## Testing your own code

Swap the HTTP layer for `MockHttpClient`; no network or keys needed:

```php
use PhpAiGuard\Http\{MockHttpClient, HttpResponse};

$http = new MockHttpClient();
$http->queue(new HttpResponse(200, json_encode([
    'content' => [['type' => 'text', 'text' => 'Hello!']],
    'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
])));

$ai = PhpAiGuard\AiGuard::create($config + ['http_client' => $http, 'storage' => ['driver' => 'memory']]);
// … assert on $http->lastRequestJson()
```

`FrozenClock` and `RecordingSleeper` make time-based behaviour deterministic.

Library development:

```bash
composer install
composer test        # PHPUnit (150 tests, no network needed)
composer analyse     # PHPStan level 8
composer cs          # PHP-CS-Fixer, PSR-12
```

MySQL tests run when `AI_GUARD_TEST_MYSQL_DSN`, `AI_GUARD_TEST_MYSQL_USER` and `AI_GUARD_TEST_MYSQL_PASSWORD` are set (CI uses MariaDB).

---

## FAQ

**Are the bundled prices correct?**
They are **example values** copied from provider pricing pages on 2026-10-17. Prices change (Gemini's current ones are promotional until 2026-12-31). Copy [`config/pricing.example.php`](config/pricing.example.php), check every row, and keep it current: your budgets are only as accurate as your prices.

**Does it support streaming?**
Not yet: see the roadmap. Each call waits for the full answer, which keeps budgeting exact and works on every host.

**My host disables `proc_open`, `exec` or `pcntl`. Does that matter?**
No. The library only needs cURL and (for SQLite/MySQL) PDO. `proc_open` is used only by the test suite.

**What happens if the database is down?**
Budget and rate-limit checks throw `StorageException`: php-ai-guard fails closed rather than spending without limits. A failure to write the usage log is reported to your PSR-3 logger but does not break the user's request.

**Can I use it with Laravel, Symfony or WordPress?**
Yes: it is plain PHP with no global state. Pass your framework's PSR-3 logger, PSR-16 cache or PSR-18 client if you like (`Psr18HttpClient` adapter). Dedicated bridges are on the roadmap.

**Is the token estimate accurate?**
It is deliberately pessimistic (it over-estimates English by about 30%) so that budget checks err on the safe side. The real usage reported by the provider is what gets recorded.

**How do I use my company's internal gateway / Azure / vLLM?**
If it speaks the OpenAI Chat Completions format, use `'type' => 'openai-compatible'` with its `base_url`, plus `headers` and `max_tokens_param` as needed.

**Why is temperature not sent by default?**
Newer Anthropic models reject non-default temperatures, so php-ai-guard sends `temperature` only when you set it (per call or in `defaults`).

**Is a 4xx from one provider really not worth retrying elsewhere?**
A 400 means the request itself is invalid (too long, bad parameter). Silently retrying it on another, possibly more expensive, provider hides bugs and can multiply costs. Fix the request instead.

---

## Roadmap

- Laravel bridge (service provider, config publishing, facade, queue-friendly)
- WordPress plugin bridge (settings page, `wpdb` storage, capabilities for the dashboard)
- Streaming responses with incremental budget accounting
- Embeddings API with the same budgets and caching
- Tool / function calling across providers
- Images and PDFs as input
- Provider token-counting endpoints for exact pre-call estimates
- Per-tenant pricing markups for SaaS resellers

Contributions welcome: see [CONTRIBUTING.md](CONTRIBUTING.md).

---

## Built by

__Lal Chand / Codic Systems._ ([website](https://codicsystems.com) · [GitHub](https://github.com/lalchand07))

## License

MIT. See [LICENSE](LICENSE).
