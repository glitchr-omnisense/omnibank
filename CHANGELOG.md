# Changelog

## [Unreleased]

### Added

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
