# Changelog

## Unreleased - 0.1.0 development

- Standalone Composer package for PHP 8.2+ and Laravel 12.
- Typed M2 authorization, status, recovery, completion, entitlements, statements,
  receipt delivery, billing pull and acknowledgment.
- Explicit M1 execution, status and receipt APIs using the M2 authorization token.
- Short-lived RS256 machine credentials, pinned billing key rings and signed webhook verification.
- Optional host-scoped inbox migration, transactional invoicing hook, replay-safe acknowledgment,
  bounded polling command, opt-in webhook controller and deterministic transport fakes.
- Backend-schema compatibility tests, Laravel integration tests and PHP matrix CI.
- Opt-in real PHP-to-FastAPI HTTP qualification with isolated PostgreSQL accounting/inbox schemas,
  polling/webhook concurrency, unknown-outcome protection and acknowledgment-response-loss replay.
- Normalize Python scientific decimal strings such as `0E-12` exactly for billing amounts.

Only `m1-dummy-v1` and `m2-dummy-v1` are supported. Production use is rejected.
No tagged release, approved C01-C08 compatibility, live portal integration or human acceptance is claimed.
