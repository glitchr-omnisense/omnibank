# Changelog

## [Unreleased]

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
