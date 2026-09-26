# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-26

First release, for PHP 8.3 and later.

### Added

- `CloudClient` for the LIVCK Cloud API v1 over any PSR-18 client (Guzzle is set up
  automatically when installed). Immutable, free of global state, with `withOptions()`,
  `withLocale()` and `withHttpClient()`.
- Tags: filter by label or key, `findByLabel()`, find-or-create `ensure()`, create, rename,
  recolor and delete.
- Services: list by tag; create through a typed `ServiceBuilder` per check type (HTTP, TCP,
  DNS, ICMP, SSL, manual) with a conditions DSL and optional validation against the live
  check-type catalog; partial updates through `UpdateService` that keep stored secrets;
  pause, resume, status overrides and delete.
- Service figures and history: metrics, daily uptime, response times, the keyset-paginated
  check history, and the incidents and maintenance windows of one service.
- Incidents and maintenance windows, read-only: lists filtered by service ids, status, time
  window and kind, and single items with their timeline.
- Status pages: create, update, publish, unpublish, delete, lookup by slug (`findBySlug()`,
  `StatuspageQuery::withSlug()`), and logo and favicon uploads; components, including groups
  that sync with a tag; custom domains with the DNS records to publish and verification on
  demand.
- `me()`, `probes()` and `checkTypes()`.
- Typed exceptions for every error the API returns, from validation and plan limits to rate
  limits and transport failures.
- Retries with exponential backoff and `Retry-After` (waited for as announced, never less
  than a second: a `Retry-After: 0` is a rounded "less than a second", not "now"), and an
  `Idempotency-Key` on every POST:
  generated per call, or your own through the `$idempotencyKey` parameter of every creating
  method, so a create is safe to repeat after a crash; switchable off per request or globally.
- Offset and keyset pagination with lazy iteration.
- Forward compatibility: the raw payload on every DTO, `Unrecognized` enum cases, raw keys on
  builders and payloads, and `request()` and `send()` for endpoints not wrapped yet.
- `CloudClient::fake()` with `MockResponse` and request assertions for testing integrations.
- Examples for resellers: onboarding, a customer overview, a billing sync, offboarding, error
  handling and testing.

[1.0.0]: https://github.com/LIVCK/cloud-php/releases/tag/v1.0.0
