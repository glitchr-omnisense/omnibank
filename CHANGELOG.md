# Changelog

## [Unreleased]

### Added

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
