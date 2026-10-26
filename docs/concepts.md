---
title: Concepts
order: 20
---

# Concepts

## Gateways, factories, the registry

### `GatewayInterface`

`Omnibank\GatewayInterface` is one bank access, configured: statement files, a bank's own API or an
aggregator, the same questions for all of them.

| Method | Answers |
|---|---|
| `getName(): string` | the factory's name (`files`, `qonto`...) |
| `getTitle(): string` | the factory's title (`Statement files`, `Qonto`...) |
| `supports(string $request): bool` | whether an action answers that request class (`Request\Transfer::class`...) |
| `execute(Request $request): Request` | the request, answered by the first action that supports it |
| `connect(Connection $connection, string $returnUrl): ConnectResult` | opens or renews the access: the page to send the user to, the connection to keep |
| `accounts(Connection $connection): array` | `list<Account>` |
| `balances(Connection $connection, Account $account): array` | `list<Balance>` |
| `transactions(Connection $connection, Account $account, ?DateTimeInterface $since = null, ?DateTimeInterface $until = null): array` | `list<BankTransaction>`, oldest first, both dates included |
| `transfer(Connection $connection, Transfer $transfer): TransferResult` | |
| `notify(string $body, array $headers = []): Notification` | a webhook, its signature checked |

Each typed method is `execute()` with its request. A request no action supports is a
`RequestNotSupportedException` (`The "qonto" gateway does not support Transfer.`); an action that
leaves its request unanswered is a `ProviderException`.

### `Gateway`

`Omnibank\Gateway` (`final`) is the implementation every factory builds: a name, a title and a list
of actions. `supports()` asks each action with a probe of the request class made without its
constructor; `execute()` runs the first action that supports the request.

### `GatewayFactoryInterface` and `GatewayFactory`

`Omnibank\GatewayFactoryInterface`: `getName(): string` (what `factory:` names in a configuration)
and `create(array $options = []): GatewayInterface`.

`Omnibank\GatewayFactory` is the abstract base every provider extends. Its constructor takes an
optional `Symfony\Contracts\HttpClient\HttpClientInterface` (`$this->http`), the client the
provider calls with; a provider that calls an API makes its own (`HttpClient::create()`) when given
none. `create()`:

1. fills a `Config` with the options given, then the factory's defaults (`populateConfig()`) under
   them;
2. checks the keys of `omnibank.required_options`: one missing or empty (`null`, `''`, `[]`) is an
   `InvalidConfigException` naming them all (`The "powens" gateway needs: domain, client_id.`);
3. builds the API client from `omnibank.api` (a closure of the `Config` is called);
4. collects every `omnibank.action.<name>` (a closure of the `Config` is called), hands the API to
   the actions that implement `ApiAwareInterface`, and returns a `Gateway`.

Building a gateway calls nothing over the network.

### `Config`

`Omnibank\Config` (`final`, an `ArrayObject`) is a gateway's configuration while its factory builds
it: `defaults(array)` sets the keys not set yet, `validateNotEmpty(array $keys)`, `get($key,
$default)`. The keys of the family:

| Key | |
|---|---|
| `omnibank.factory_name`, `omnibank.factory_title` | the gateway's name and title |
| `omnibank.required_options` | the option names that must not be empty |
| `omnibank.api` | the API client, or a closure of the `Config` that makes it |
| `omnibank.action.<name>` | an action, or a closure of the `Config` that makes it |

Everything else is a provider's options ([Configuration](configuration.md)).

### `Registry`

`Omnibank\Registry` (`final`): the application's gateways by name.

```php
new Registry(iterable $factories, array $config);
// $config: ['<name>' => ['factory' => '<factory name>', 'options' => [...]], ...]
```

| Method | |
|---|---|
| `get(string $name): GatewayInterface` | built on first use, then kept. `No "x" gateway; configured: a, b.` and `No "y" factory for the "x" gateway; installed: files, qonto.` as `InvalidConfigException` |
| `has(string $name): bool` | configured or not |
| `options(string $name): array` | the options a gateway is configured with |
| `create(string $name, array $overrides = []): GatewayInterface` | a gateway built afresh, `$overrides` over its options (keys typed in a back office); not kept |
| `names(): array` | the configured names |
| `all(): array` | every gateway, built, by name |
| `factories(): array` | the factory names installed |

### Requests and actions

`Omnibank\Request\Request` (abstract) carries a question and its result: `setResult()`,
`getResult()`, `isAnswered()`. Every request is `final` with public readonly properties:

| Request | Properties | Result |
|---|---|---|
| `Connect` | `connection`, `returnUrl` | `getConnectResult(): ?ConnectResult` |
| `FetchAccounts` | `connection` | `getAccounts(): list<Account>` |
| `FetchBalances` | `connection`, `account` | `getBalances(): list<Balance>` |
| `FetchTransactions` | `connection`, `account`, `since`, `until` | `getTransactions(): list<BankTransaction>`; `covers(DateTimeInterface $bookedOn): bool` compares days (`Y-m-d`), both ends included |
| `Transfer` | `connection`, `transfer` | `getTransferResult(): ?TransferResult` |
| `Notify` | `body`, `headers` | `getNotification(): ?Notification`; `header(string $name): ?string` reads a header whatever its case (the first value of a list) |

`Omnibank\Action\ActionInterface`: `supports(Request): bool`, `execute(Request): void` (sets the
result or throws). `ApiAwareInterface::setApi(object $api)`, and `ApiAwareTrait`, which checks the
API's class (`$this->apiClass`, set in the action's constructor) and throws an
`InvalidConfigException` for another one.

## The models

Every model is a `final readonly` class: built once, never changed.

### `Money`

An amount in a currency's minor units, **signed**: `-1250 EUR` is 12.50 euros gone out of the
account.

| Member | |
|---|---|
| `amount` (int), `currency` (string, upper-cased) | |
| `Money::of(int $amount, string $currency)` | |
| `Money::fromDecimal(string\|int\|float $decimal, string $currency)` | a string is read digit by digit, never through a float (`'1234.56'` is exactly 123456), rounded half up on what lies beyond the currency's digits; a number is multiplied and rounded; anything else is an `\InvalidArgumentException` |
| `decimal(): string` | `"-12.50"`; no decimal point for a currency without minor units |
| `isNegative()`, `isZero()`, `negate()`, `abs()`, `equals(Money)` | |
| `Money::minorDigits(string $currency): int` | 0 for BIF, CLP, DJF, GNF, ISK, JPY, KMF, KRW, MGA, PYG, RWF, UGX, UYI, VND, VUV, XAF, XOF, XPF; 3 for BHD, IQD, JOD, KWD, LYD, OMR, TND; 2 otherwise |
| `__toString()` | `"-12.50 EUR"` |

### `Account`

| Property | Type | |
|---|---|---|
| `id` | string | the provider's: what `balances()` and `transactions()` are asked with (files: the IBAN, else the statement's account number or the file's name) |
| `iban`, `bic` | ?string | |
| `name` | string | |
| `currency` | string | ISO 4217 |
| `type` | ?string | the provider's word: `checking`, `savings`, `card`, `external`... |
| `raw` | array | the provider's whole answer, for what the model leaves out |

### `Balance`

`amount` (`Money`), `at` (`DateTimeImmutable`), `type` (string, default `booked`). The types used:
`booked` (what has been entered), `available` (what can be spent), `expected` (with what is coming),
and each provider's own (`opening`, `instant`, `interim_booked`... - see the providers).

### `BankTransaction`

| Property | Type | |
|---|---|---|
| `id` | string | stable: the bank's reference, or a fingerprint of the line, so the same line read twice is the same transaction |
| `bookedOn` | `DateTimeImmutable` | the booking date |
| `valueOn` | ?`DateTimeImmutable` | the value date, when given |
| `amount` | `Money` | **negative when money leaves the account** |
| `label` | string | the bank's label, as on the statement |
| `counterpartyName`, `counterpartyIban` | ?string | |
| `reference` | ?string | what the payer wrote (remittance information), or an end-to-end id |
| `raw` | array | the provider's line, whole |

`isDebit()` is `amount->isNegative()`.

### `Connection`, `Consent`, `ConsentStatus`

`Connection` is what the application keeps for one bank access and hands back on every call:
`state` (array: the provider's user, its tokens, the statement files) and `consent` (`Consent`,
`NONE` by default). `withState(array)` replaces the state, `withConsent(Consent)` the consent,
`get($key, $default)` reads a state key; `toArray()` / `Connection::fromArray()` keep it as JSON
(`{"state": ..., "consent": {"status": ..., "expires_at": ...}}`), and it serializes. It holds
credentials: store it as secrets are stored.

`Consent`: `status` (`ConsentStatus`), `expiresAt` (?`DateTimeImmutable`). `Consent::none()`,
`active(?expiresAt)`, `needsRenewal(?expiresAt)`, `revoked()`; `isActive(?$now)` is true when ACTIVE
and not past `expiresAt`; `toArray()` (`expires_at` in `DATE_ATOM`), `fromArray()` (an unknown
status reads as NONE).

| `ConsentStatus` | Value | |
|---|---|---|
| `ACTIVE` | `active` | the accounts can be read |
| `NEEDS_RENEWAL` | `needs_renewal` | the user must go through the provider's page again (expired consent, SCA, new password) |
| `REVOKED` | `revoked` | the user or the bank withdrew it |
| `NONE` | `none` | never given |

### `ConnectResult`

`url` (?string: the page to send the user to, null when there is nothing to do) and `connection`
(the connection to keep: its state may have changed). `isRedirect()` is `url !== null`.

### `Transfer` and `TransferResult`

`Transfer`, a SEPA credit transfer to make:

| Property | Type | |
|---|---|---|
| `debtorIban` | string | the account the money leaves |
| `creditorName`, `creditorIban` | string | |
| `creditorBic` | ?string | |
| `amount` | `Money` | positive |
| `reference` | string | the remittance information the creditor sees, 140 characters at most |
| `endToEndId` | ?string | travels with the money to the creditor's bank |
| `executionDate` | ?`DateTimeImmutable` | today when null |

`TransferResult`: `id` (the provider's, or the file's message id), `status` (`generated` for a file
to upload, or the provider's word), `file` and `fileName` (?string: the pain.001 to hand the bank).

### `Notification`

`type` (the provider's name for the event: `CONNECTION_SYNCED`, `item.refreshed`...),
`connectionId` (?string: the provider's connection, a Powens connection or a Bridge item),
`consent` (`Consent`: what the event means for the user's consent; `NONE` when it says nothing of
it), `raw` (the event, decoded).
