# php-ai-guard — implementation plan

This is the design document written before implementation. It records the
public API, the class layout, the storage schema and the budget algorithm,
together with the provider facts the implementation relies on (and where they
came from).

## 1. Goals and constraints

* One API for Anthropic, Google Gemini and any OpenAI-compatible endpoint.
* Hard USD budgets (global / tenant / user × daily / monthly), rate limits,
  per-call usage logging, response caching, fallbacks, privacy redaction.
* PHP 8.0+ (no enums, no `readonly`, no `never`, no `new` in initializers, no
  first-class callables). Only `ext-curl` and `ext-json` required. PSR-3,
  PSR-16 and PSR-18 are *optional* adapters (listed in `suggest`).
* Must run on shared cPanel hosting: no daemons, no Redis requirement, SQLite
  or MySQL via PDO, or a plain file store.

Package: `your-vendor/php-ai-guard` (placeholder vendor), namespace
`PhpAiGuard\`, CLI binary `vendor/bin/ai-guard`.

## 2. Public API

```php
$ai = AiGuard::create($config);               // array config → wired instance

$res = $ai->chat('Summarise this ...', [       // Response
    'system' => '...', 'max_tokens' => 400, 'temperature' => 0.2,
    'tier' => 'cheap',                         // or 'model' => 'groq:openai/gpt-oss-20b'
    'user' => '42', 'tenant' => 'acme', 'feature' => 'summary',
    'cache' => true, 'cache_ttl' => 3600,
]);
$res->text; $res->usage->inputTokens; $res->cost; $res->provider; $res->model;

$data = $ai->json('Extract ...', $schema, $options); // validated array

$ai->forUser('42', 'acme')->withFeature('summary')->chat('...'); // immutable scoping
$ai->send(Request $request): Response;          // full control (multi-turn)
$ai->remainingBudget('user', '42', 'monthly'): ?float;
```

## 3. Class layout

```
src/
  AiGuard.php                 Facade + orchestration (rate limit → route → per-target loop)
  AiGuardFactory.php          Builds AiGuard from a config array
  Context.php                 tenant / user / feature
  Message.php                 role + content
  Request.php                 messages, system, maxTokens, temperature, schema/json flag, route, cache opts
  Response.php                text, json, usage, cost, latencyMs, provider, model, cacheHit, finishReason, attempts
  Usage.php                   inputTokens, outputTokens, cacheReadTokens, cacheWriteTokens
  Attempt.php                 one provider attempt (target, status, error class, latency)
  Clock/ClockInterface, SystemClock, FrozenClock
  Http/
    HttpClientInterface       send(HttpRequest): HttpResponse
    HttpRequest, HttpResponse
    CurlHttpClient            default transport (timeouts, header parsing)
    Psr18HttpClient           adapter for any PSR-18 client + PSR-17 factories
    MockHttpClient            queue of canned responses / exceptions (tests, user tests)
  Provider/
    ProviderInterface         name(), allowsPersonalData(), complete(Request, model): ProviderResult
    ProviderResult            text, usage, finishReason, raw
    AbstractHttpProvider      shared HTTP + error→exception mapping, Retry-After parsing
    AnthropicProvider         POST /v1/messages
    GeminiProvider            POST /v1beta/models/{model}:generateContent
    OpenAiCompatibleProvider  POST {base}/chat/completions (+ presets)
    ProviderPresets           openai, groq, openrouter, nvidia, ollama defaults
  Routing/
    Target                    provider + model ("anthropic:claude-haiku-5-5")
    Router                    tier / feature / explicit model → list<Target> (fallback chain)
  Pricing/
    ModelPrice                USD per 1M tokens: input, output, cache_read, cache_write (+ long-context tier)
    PricingTable              lookup (exact model, then '*'), cost(Usage), maxCost(estimate)
  Budget/
    BudgetPolicy              limits per scope/window, overrides per id
    BudgetManager             reserve / settle / release, friendly refusals
    RateLimiter               fixed one-minute windows on the same counters
    TokenEstimator            conservative input-token estimate
  Cache/
    ResponseCacheInterface    get(key): ?array, set(key, array, ttl)
    NullCache, ArrayCache, FileCache, Psr16Cache
    CacheKey                  sha256(provider, model, normalized request)
  Privacy/
    Redactor                  apply(text) → RedactionResult; restore(text|array)
    RedactionResult           text + placeholder map
    Pattern/ PatternInterface, RegexPattern, EmailPattern, PhonePattern,
             CardNumberPattern (Luhn), MrzPattern, IbanPattern (mod-97)
  Json/
    SchemaValidator           JSON-schema subset → list of error strings
    JsonExtractor             strips code fences / prose, json_decode
  Storage/
    StorageInterface          counters + usage log + aggregation
    UsageRecord
    InMemoryStorage, FileStorage (flock), PdoStorage (MySQL/MariaDB/SQLite)
    SchemaInstaller           creates tables (used by CLI `install`)
  Report/
    Reporter                  aggregate(month, by) → rows
    TableRenderer, CsvRenderer
  Cli/Application.php         `report`, `install` commands
  Exception/ ...              see §8
bin/ai-guard                  CLI entry
dashboard/dashboard.php       include-able HTML dashboard
config/pricing.example.php    EXAMPLE prices only
sql/mysql.sql, sql/sqlite.sql
```

## 4. Request flow (`AiGuard::send`)

1. Merge context (tenant/user/feature) and defaults (max tokens 1024).
2. **Rate limit**: one hit per user call on `rate:user:{id}` and
   `rate:tenant:{id}` (and optional global) for the current minute; refuse with
   `RateLimitExceededException` (includes seconds until the window resets).
3. **Route**: explicit `model` → `[target]`; else `tier` → configured chain;
   else feature → tier mapping; else `default_tier`.
4. For each target in the chain:
   1. **Privacy**: if redaction is enabled, or the provider has
      `allows_personal_data = false`, redact (placeholders `[EMAIL_1]`). If
      the policy is `skip` instead of `redact` and PII is found, skip target.
   2. **Cache** lookup on the (redacted) request → hit returns immediately,
      cost 0, logged with `cache_hit = 1`.
   3. **Pricing**: unknown model → `PricingUnknownException` unless
      `unknown_pricing = allow` (cost then logged as 0 with a warning).
   4. **Budget reserve** of the worst-case cost (algorithm in §6). Refused →
      `BudgetExceededException` (or try next target if
      `budget_fallback = true`).
   5. **Call** with retries: retryable errors (timeout, network, 429, 5xx,
      Anthropic 529) are retried up to `max_retries` with exponential backoff
      + full jitter, honouring `Retry-After` (capped by `max_retry_after`;
      a longer wait moves on to the next target instead of sleeping).
      Non-retryable errors (400/401/403/404/413/422, content blocks) are
      thrown immediately — **no fallback on 4xx validation errors**.
   6. **Settle** the reservation with the real cost (or release it on failure)
      and write a usage log row per attempt.
   7. Restore placeholders in the response if `privacy.restore = true`;
      store in cache (the redacted version).
5. Exhausted chain → `AllProvidersFailedException` with every attempt.

`json()` adds a JSON instruction (and the provider's native JSON mode when
configured), extracts + validates the output; on failure it sends **one**
follow-up turn containing the validation errors, then throws
`JsonValidationException` (with the errors and raw text).

## 5. Provider facts (from the official docs, read 2026-10-17)

| Provider | Endpoint | Auth | Max tokens field | JSON mode | Usage fields |
|---|---|---|---|---|---|
| Anthropic | `POST https://api.anthropic.com/v1/messages`, header `anthropic-version: 2023-06-01` | `x-api-key` | `max_tokens` (required) | `output_config.format = {type: json_schema, schema}` (supported models only → opt-in) | `input_tokens`, `output_tokens`, `cache_read_input_tokens`, `cache_creation_input_tokens` (total input = sum of the three) |
| Gemini | `POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` | `x-goog-api-key` | `generationConfig.maxOutputTokens` | `generationConfig.responseFormat.text = {mimeType: APPLICATION_JSON, schema}` (current) or legacy `responseMimeType` + `responseJsonSchema` | `promptTokenCount` (includes cached), `cachedContentTokenCount`, `candidatesTokenCount`, `thoughtsTokenCount` (billed as output) |
| OpenAI | `POST https://api.openai.com/v1/chat/completions` | `Authorization: Bearer` | `max_completion_tokens` (`max_tokens` deprecated) | `response_format` `json_schema` / `json_object` | `prompt_tokens` (includes cached), `completion_tokens`, `prompt_tokens_details.cached_tokens` |
| Groq | `https://api.groq.com/openai/v1` | Bearer | `max_completion_tokens` | `json_object` | as OpenAI |
| OpenRouter | `https://openrouter.ai/api/v1` (+ optional `HTTP-Referer`, `X-OpenRouter-Title`) | Bearer | `max_tokens` | `json_object` | as OpenAI + `prompt_tokens_details.cache_write_tokens` |
| Ollama | `http://localhost:11434/v1` | none (any value) | `max_tokens` | `json_object` | as OpenAI |
| NVIDIA NIM | `https://integrate.api.nvidia.com/v1` | Bearer | `max_tokens` | prompt-only (default) | as OpenAI |

Notes:
* Anthropic: `temperature` is deprecated for models after Opus 4.6 (only 1.0
  accepted) → only sent when the caller sets it. Prompt caching: top-level
  `cache_control: {type: ephemeral}` (optional `ttl: "1h"`) = automatic
  caching. Opt-in per provider or per request because cache writes cost
  1.25× (5 m) / 2× (1 h) the input price; reads cost 0.1× (some models less).
  Errors: 429 rate limit, 500, 504, 529 overloaded → retryable.
* Gemini: parts with `thought: true` are excluded from the text;
  `promptFeedback.blockReason` → non-retryable `ContentBlockedException`.
* NVIDIA's own docs (docs.api.nvidia.com / docs.nvidia.com) were blocked from
  this environment; NIM is supported only through the generic
  OpenAI-compatible provider with conservative defaults (see README).

## 6. Budget algorithm

Money is stored as **integer micro-dollars** (1 USD = 1 000 000) so counters
are exact and atomic SQL arithmetic is safe.

Counter keys (one row each):

```
budget:global:-:day:2026-10-17     budget:global:-:month:2026-10
budget:tenant:acme:day:2026-10-17  budget:tenant:acme:month:2026-10
budget:user:42:day:2026-10-17      budget:user:42:month:2026-10
rate:user:42:2026-10-17T12:30      rate:tenant:acme:2026-10-17T12:30
```

Windows are calendar day / calendar month in the configured timezone (default
UTC). Each row stores an `expires_at` so `prune()` can delete old rows.

**Estimate** (worst case for one attempt on a target):

```
inputTokens  = TokenEstimator(system + messages + schema instruction)
             = max(ceil(bytes / 3), ceil(chars × 1.2)) + 8 per message + 16
inputPrice   = max(input, cache_write if prompt caching on)   (per 1M)
estimate     = inputTokens × inputPrice / 1e6 + maxTokens × output / 1e6
```

**Reserve** (atomic, all-or-nothing across every applicable key):

```
for each key in sorted(keys):            # stable order avoids deadlocks
    INSERT row (amount 0) if missing
BEGIN (SQLite: BEGIN IMMEDIATE)
for each key in sorted(keys) with a limit and estimate > 0:
    UPDATE counters SET amount = amount + :est
     WHERE counter_key = :key AND amount + :est <= :limit
    if affectedRows == 0: ROLLBACK; refuse (report which key)
COMMIT
```

The conditional `UPDATE` makes "check and add" a single atomic statement per
row; the transaction makes it all-or-nothing across rows. In-memory storage is
single-process; file storage holds an exclusive `flock` for the whole
read-check-write cycle.

**Settle**: after the call, `adjust(keys, actual − estimate)` (usually
negative → gives back the unused reservation). On failure, `adjust(keys,
−estimate)`. Because spending is reserved *before* the call at the worst-case
price, concurrent requests can never jointly pass the limit — the only
residual risk is the token estimate being lower than the provider's real
input count (documented; the estimator is deliberately pessimistic).

**Rate limits** use the same `reserve` call with amount 1 and limit = rpm.

**Remaining budget** = limit − counter (for UI display).

## 7. Storage schema

```sql
CREATE TABLE ai_guard_counters (
  counter_key VARCHAR(191) PRIMARY KEY,
  amount      BIGINT NOT NULL DEFAULT 0,
  expires_at  BIGINT NOT NULL,           -- unix seconds
  updated_at  BIGINT NOT NULL
);

CREATE TABLE ai_guard_usage (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,   -- INTEGER PK on SQLite
  created_at     BIGINT NOT NULL,                      -- unix seconds
  period_month   CHAR(7) NOT NULL,                     -- '2026-10' (fast reports)
  tenant_id      VARCHAR(191) NULL,
  user_id        VARCHAR(191) NULL,
  feature        VARCHAR(100) NULL,
  provider       VARCHAR(64)  NOT NULL,
  model          VARCHAR(191) NOT NULL,
  input_tokens   INT NOT NULL DEFAULT 0,
  output_tokens  INT NOT NULL DEFAULT 0,
  cached_tokens  INT NOT NULL DEFAULT 0,
  cost_micros    BIGINT NOT NULL DEFAULT 0,
  latency_ms     INT NOT NULL DEFAULT 0,
  cache_hit      TINYINT NOT NULL DEFAULT 0,
  status         VARCHAR(32) NOT NULL,     -- ok | error | budget_refused | rate_limited | privacy_skipped
  error_type     VARCHAR(191) NULL,
  prompt_text    TEXT NULL,                -- only when log_prompts = true
  response_text  TEXT NULL                 -- only when log_prompts = true
);
-- indexes: (period_month, tenant_id), (period_month, user_id), (created_at)
```

Table names are configurable with a prefix.

## 8. Exceptions

```
AiGuardException (RuntimeException)
├── ConfigurationException
├── PricingUnknownException
├── BudgetExceededException      scope, scopeId, window, limit, spent, estimate, resetsAt, userMessage()
├── RateLimitExceededException   scope, scopeId, limit, retryAfterSeconds
├── PrivacyException             personal data blocked by provider policy
├── JsonValidationException      errors[], rawText
├── StorageException
├── AllProvidersFailedException  attempts[], previous = last error
└── ProviderException            provider, statusCode, retryable
    ├── TransientProviderException      (retryable)
    │   ├── ProviderTimeoutException
    │   ├── ProviderNetworkException
    │   ├── ProviderRateLimitException  retryAfter
    │   └── ProviderUnavailableException (5xx / 529)
    ├── InvalidRequestException          (400, 404, 413, 422)
    ├── AuthenticationException          (401, 403)
    └── ContentBlockedException          (safety block)
```

## 9. Testing strategy

* `MockHttpClient` + recorded sample bodies in `tests/Fixtures/` copied from
  the provider docs' documented response shapes — no network, no keys.
* `FrozenClock` + `NullSleeper`/recording sleeper for deterministic retries.
* SQLite concurrency test: several PHP worker processes (via `proc_open`)
  hammer `reserve()` on one database file; total reserved must never exceed
  the limit and must equal limit when demand exceeds it.
* CI matrix PHP 8.0–8.4; PHPStan level 8; PHP-CS-Fixer (PSR-12).
