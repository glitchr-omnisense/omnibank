# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Release
- Requires `omnisense/contracts` `^1.0`, a stable version, where it required `^1.0@dev`;
  `extra.branch-alias` `dev-1.x` → `1.x-dev`.
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml: the package installed alone,
  PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml` (that CI on the tag,
  then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the package's own tests.
- A factory's HTTP client always goes through the kernel's `Http\NoRetry`: the `class_exists()`
  fallback for an omnisense/contracts older than 1568f2c is gone (contracts 1.0 has it).
- `phpstan.neon` accepts the bundle's provider classes as absent (the package installed alone, as
  its own CI does) or known (the family's harness, every provider installed).
- README: the first call reads a CSV statement given inline (no bank account, no network), and says
  what each provider needs for a real call.

### Added

- Writes sent once: the HTTP client a factory is given keeps its retries off (omnisense/contracts'
  `Http\NoRetry`), so Symfony's `RetryableHttpClient` (`framework.http_client`'s `retry_failed`) never
  creates a Powens user or a Bridge connect session twice. A `MockHttpClient` is kept as given.
  docs/policies.md.

### Changed

- omnisense/omnibank is built on omnisense/contracts, the kernel of the omni families; the public
  API is unchanged. `Config`, `Registry`, `GatewayFactory` and `Http\Redaction` are thin subclasses
  of the kernel's (prefix `omnibank`); `GatewayInterface` extends its `ProviderInterface`
  (`capabilities()`: the operations a gateway supports and its usage policy),
  `GatewayFactoryInterface` its `ProviderFactoryInterface`; `OmnibankException` extends its
  `ExceptionInterface`, `InvalidConfigException` its `InvalidConfigException` (still a
  `LogicException`). `UnavailableException` is no longer final.
- The Symfony bundle is the only class needing a framework: its components stay suggested, and
  its file names them when they are missing. `Tests/LoadAllTest` (`Testing\BareTestCase`) loads
  every other class with omnisense/contracts and the HTTP client contracts alone.

### Added

- Usage policies ([docs/policies.md](docs/policies.md)): a provider declares its `policy.json`
  (`omnibank.policy`), a gateway gives it; `Registry::policy()`, `policies()`, `guard()` and
  `guarded()` (the gateway behind its guard, a webhook never held; `get()` unguarded as before);
  `policy` and `usage` beside a gateway's options; a shared `RateLimiterInterface` as the
  registry's third argument; the bundle's `policy`, `usage` and `limiter`. A limit per user counts
  per the provider's user the connection names (`omnibank.user_key`).
- `Http\Throttle`: a provider's API client honours the waits it asks for - no call while one
  lasts (`Exception\UsageLimitReachedException`, nothing sent), a 429 an
  `Exception\RateLimitedException` (an `UnavailableException`, with `retryAfter`). Nothing retries a
  call.
- The harness mounts omnisense/contracts' checkout (`OMNISENSE_CONTRACTS`) and runs its tests, and
  runs PHPStan at level max on every package (`phpstan`; `phpstan-baseline.neon` holds what was
  there when PHPStan came in, 71 errors in the core, none on a line this change wrote).

### Documentation

- `docs/usage-webhooks.md`: webhooks are optional - a scheduled read catches up everything they
  say, and a development machine needs neither a tunnel nor a webhook registered (never a
  `localhost` URL at the provider).

### Added

- Registering the webhook from the application, an optional capability (no change to
  `GatewayInterface`): `Request\RegisterWebhook` (URL, events; registering an address again
  replaces it), answered by a `Model\Webhook` (provider id, URL, events, and the signing secret,
  given at creation only), `Request\FetchWebhooks`, `Request\DeleteWebhook`. `Http\PublicUrl::unreachable()`
  says why an address no provider can reach (localhost, `*.local`, `*.test`, private IP, HTTP)
  is refused. No provider supports it yet: Powens and Bridge document no API for it
  (`docs/usage-webhooks.md`).
- The words a dashboard needs, the same for every gateway: `Model\AccountKind` (checking, savings,
  term deposit, card, loan, consumer credit, revolving, market, life insurance, savings plan,
  retirement, other investment, unknown; `group()`, `isLiability()`) on `Account::$kind`,
  `Model\TransactionCategory` (income, housing, household, food, transport, shopping, health,
  leisure, subscriptions, insurance, taxes, bank fees, loan, savings, business, transfer, cash,
  other, unknown) on `BankTransaction::$category` (null when the provider does not categorise), and
  `Model\ConnectionState` (ok, pending, action needed, wrong credentials, bank unavailable, bug,
  unknown; `group()`, `needsUser()`, `ofFailure()`) on `Account::$state`, `Notification::$state` and
  `ProviderException::$connectionState`. Each is a new last constructor argument with a default:
  models built as before stay as they were. `Account::$type` keeps the provider's word.

- `Http\Redaction`: what the providers call at their catch of the HTTP client's exception, so
  that a `UnavailableException` carries none of the request's secrets - its message with the key's
  parameters, the URL's user part and the request's secrets masked as `***` (`text()`), as
  previous a copy of the client's exception, the same class and code (`copy()`), and no trace
  of the chain keeping its arguments (`withoutArguments()`).

### Fixed

- `Gateway::execute()` throws what an action throws without arguments in its trace: with
  `zend.exception_ignore_args` off, each frame kept the request, and the request the connection
  with its tokens (Powens' `auth_token`, Bridge's `access_token`).
- `GatewayFactory::create()` throws what fails without arguments in its trace: with
  `zend.exception_ignore_args` off, an `InvalidConfigException` (a missing option) kept the
  options given, credentials included, in the arguments of `create()`.
