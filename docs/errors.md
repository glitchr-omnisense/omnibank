---
title: Errors
order: 50
---

# Errors and exceptions

```
Omnibank\Exception\OmnibankException (an interface, \Throwable)
├── InvalidConfigException          extends \LogicException      a gateway misconfigured
├── RequestNotSupportedException    extends \LogicException      the provider does not do that
└── ProviderException               extends \RuntimeException    the provider refused
    ├── InvalidNotificationException                             a webhook that is not the provider's
    └── UnavailableException                                     the provider could not be reached or failed
```

`catch (OmnibankException $e)` catches all of them. `ProviderException` has two public readonly
properties: `provider` (the factory's name) and `providerCode` (the provider's own error code, or
the HTTP status, when there was one); its message starts with `[<provider>] `.

## `InvalidConfigException`

| Message | Thrown by |
|---|---|
| `The "<factory>" gateway needs: <options>.` | `GatewayFactory::create()`, a required option missing or empty |
| `No "x" gateway; configured: a, b.` / `No "y" factory for the "x" gateway; installed: ...` | `Registry::get()`, `Registry::create()` |
| `<Action> needs a <Api>, got <class>.` | `ApiAwareTrait::setApi()`, an action given another provider's API |
| `The "files" gateway writes transfers with the debtor's name: set its "debtor_name" option.` | files, `transfer()` |
| `A CSV without a header needs its columns as indexes: ...` | files, a CSV read with `header: false` |
| `The "powens" gateway needs a return URL: ...`, `The "bridge" gateway needs a return URL: ...` | `connect()` with an empty return URL and no `redirect_uri` / `callback_url` |

## `RequestNotSupportedException`

`The "qonto" gateway does not support Transfer.` - thrown by `Gateway::execute()` for a request no
action supports. `supports()` says it beforehand:

| | Transfer | Notify |
|---|---|---|
| files | yes | not supported |
| qonto | not supported | not supported |
| powens | not supported | yes |
| bridge | not supported | yes |

## `ProviderException`

The provider refused, or a file cannot be read:

- an API's 4xx: Qonto's `errors[0].detail` (its `code` as `providerCode`), Powens' `description`,
  Bridge's `errors[0].message`;
- the connection lacks what the provider needs: `The connection has no Powens user yet: connect()
  first.` (and Bridge's), `Qonto lists transactions by IBAN: the account has none.`;
- an account the provider does not know (`No statement for the account "x".`, `The organization has
  no account "x".`);
- a link out of the provider's host given in a pagination (Powens, Bridge): it is not followed, so
  the user's token does not leave;
- a file that is not what it says (not XML, no statement in it, a CSV line with an amount and a
  date in no format, no header line found), a transfer the files gateway refuses (not euros, not
  positive, an IBAN or BIC that does not hold);
- an action that left its request unanswered (`Gateway::execute()`).

## `UnavailableException`

The provider could not be reached or failed on its side: a transport error of the HTTP client
(network, DNS, timeout), an HTTP 5xx, a 429, or a body that is not JSON. **Nothing is known**: it
is never "no accounts" nor "no transactions". Keep what was read before and try again later.

## No secret in a failure

What a provider throws keeps the request's secrets out, for a log that writes the whole chain,
arguments included (`zend.exception_ignore_args` off). The HTTP client's message quotes the URL
called: in the `UnavailableException`'s message its key parameters, a password in its user part and every secret
of the request (a key, a password, a bearer or basic credential) are masked as `***`
(`Omnibank\Http\Redaction::text()`). The previous exception is a copy of the client's
(`Redaction::copy()`: the same class and code, that message, its own previous copied the same
way, without the response or request a client's exception holds), and no trace of the chain keeps
its arguments, where the options, headers and body stood (`Redaction::withoutArguments()`). A
`GatewayFactory::create()` that fails (a missing option) throws without arguments in its trace
either: they held the options, credentials included.

## `InvalidNotificationException`

A webhook whose signature does not hold, that carries none, or that cannot be checked (no
`webhook_secret`) - [Webhooks](usage-webhooks.md).

## Not an `OmnibankException`

`Money::fromDecimal()` throws a plain `\InvalidArgumentException` for a string that is not a
decimal (`"12,50"`, `"abc"`); the readers turn a bank's amounts into plain decimals before calling
it.

## What to catch

```php
use Omnibank\Exception\{InvalidNotificationException, ProviderException, RequestNotSupportedException, UnavailableException};

try {
    $transactions = $gateway->transactions($connection, $account, $since);
} catch (UnavailableException) {
    // nothing known: keep the previous data, retry later
} catch (ProviderException $e) {
    // refused: $e->provider, $e->providerCode; a consent to renew, credentials to fix
}
```

`InvalidConfigException` and `RequestNotSupportedException` are errors of the application's code or
configuration: test `supports()` before offering a feature, and let a missing option fail loudly.
