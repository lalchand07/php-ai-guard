# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-17

First public release.

### Added
- One API for Anthropic Messages, Google Gemini `generateContent` and any
  OpenAI-compatible Chat Completions endpoint, with presets for OpenAI, Groq,
  OpenRouter, NVIDIA NIM and Ollama.
- USD budgets per global / tenant / user scope with daily and monthly windows,
  enforced before each call by an atomic worst-case reservation and settled
  to the real cost afterwards.
- Per-minute rate limits per user, tenant and application.
- Named model tiers, feature-to-tier routing and fallback chains with
  exponential backoff, jitter and `Retry-After` support. No fallback on 4xx
  validation, authentication or safety errors.
- Pricing table you control (example file included, not authoritative), with
  prompt-cache read/write and long-context prices. Unknown models are refused
  by default.
- Response cache (file, in-memory or any PSR-16 cache) keyed by provider,
  model and redacted request.
- `json()` structured output validated against a JSON-schema subset with one
  corrective retry.
- Redaction of emails, phone numbers, Luhn-checked card numbers, IBANs,
  passport/ID MRZ lines and custom patterns, with restoration in answers and
  a per-provider `allows_personal_data` flag.
- Storage drivers: SQLite and MySQL/MariaDB via PDO, plain files and memory.
- Usage log with tenant, user, feature, provider, model, tokens, cost,
  latency, cache hit and status. Prompt logging is opt-in.
- `vendor/bin/ai-guard` CLI (`report`, `install`, `prune`) with table and CSV
  output, and a self-contained HTML dashboard.
- Optional PSR-3 logger, PSR-16 cache and PSR-18 HTTP client support.
