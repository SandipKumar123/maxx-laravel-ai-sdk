# Changelog

## Documentation - 27 October 2026

- Added `docs/USER-MANUAL.md` for existing India/USA/UAE HRMS consumers: six-field connection verification, credential rotation, M2/M1 execution, uncertainty recovery, usage versus billing, and the host invoice-handler boundary.
- Documented regional locked revision versus standalone SDK source revision and local/test-only acceptance limits. No SDK runtime behavior or production contract changed in this documentation update.

## Unreleased - 0.1.0 development

- Read-only upstream contract parity/provenance checks with LF/CRLF support and regression tests.
- Locked and Laravel 12 latest/lowest CI matrices with retained test evidence and pinned
  cross-repository contract parity. Joint real-backend qualification is wired in the platform.
- Laravel 11 qualification remains blocked by Composer security advisories; owner selected
  retaining Laravel 12 support and an approved upgrade path for incompatible hosts.
- Optional platform-issued, server-scoped SDK credential mode; existing machine JWT mode
  remains the default. Credential creation, expiry, rotation and revocation are backend-owned.
- Real HTTP qualification now also verifies issued-token access, human-review denial and revocation.

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
