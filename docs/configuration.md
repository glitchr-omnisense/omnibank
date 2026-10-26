---
title: Configuration reference
order: 40
---

# Configuration reference

## The registry

```php
new Omnibank\Registry(iterable $factories, array $config);
```

| Argument | Type | |
|---|---|---|
| `$factories` | iterable of `GatewayFactoryInterface` | keyed by their `getName()`; a later factory of the same name replaces an earlier one |
| `$config` | `array<string, array{factory: string, options?: array}>` | the gateways by name |

Each gateway:

| Key | Type | Default | |
|---|---|---|---|
| `factory` | string | required | a factory's `getName()`; unknown: `InvalidConfigException` on first use |
| `options` | array | `[]` | handed to the factory's `create()` on first use; the gateway is then kept |

Nothing is checked when the registry is built; `Registry::create($name, $overrides)` builds a gateway
with other options over the configured ones, without keeping it. The Symfony bundle's tree
(`omnibank.gateways.<name>.factory`, `.options`) is the same ([Symfony](symfony.md#the-configuration-tree)).

## Provider options

Read from each factory's `populateConfig()` defaults and what its API and actions read. "Required"
options are checked by `create()`: missing or empty, an `InvalidConfigException` names them.

### `files`

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `currency` | string | `EUR` | no | a CSV's currency when neither the file's `account` nor a currency column says; also the fallback of CAMT and OFX files that give none |
| `csv` | array | `[]` | no | the CSV options for every CSV (a statement's own `csv` over them): `delimiter`, `enclosure`, `encoding`, `decimal`, `date_format`, `header`, `columns` ([omnibank/files](https://github.com/glitchr-omnisense/omnibank-files/blob/1.x/docs/index.md#csv)) |
| `debtor_name` | ?string | `null` | for `transfer()` | the account holder, as the bank knows it |
| `debtor_bic` | ?string | `null` | no | the debtor's bank's BIC; `NOTPROVIDED` goes without |
| `initiating_party` | ?string | `null` | no | who sends the file, when not the debtor |
| `batch_booking` | bool | `false` | no | the file's `BtchBookg` |

The files themselves travel in the connection: `state.statements`, a list of
`['name' => ..., 'content' => ..., 'format' => 'camt053'|'ofx'|'csv' (optional), 'account' => [...] (optional), 'csv' => [...] (optional)]`.

### `qonto`

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `login` | string | - | yes | the organization's login (Settings, Integrations, API key) |
| `secret_key` | string | - | yes | |
| `sandbox` | bool | `false` | no | `true`: `https://thirdparty-sandbox.staging.qonto.co` |
| `staging_token` | ?string | `null` | no | the sandbox's `X-Qonto-Staging-Token` |
| `host` | ?string | `null` | no | another host, whatever `sandbox` says (default `https://thirdparty.qonto.com`) |

### `powens`

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `domain` | string | - | yes | the client's domain (`<x>-sandbox.biapi.pro`); a scheme or a path given with it is cut off |
| `client_id` | string | - | yes | |
| `client_secret` | string | - | yes | |
| `redirect_uri` | ?string | `null` | no | the return URL when `connect()` is given an empty one |
| `webhook_secret` | ?string | `null` | for `notify()` | |
| `webhook_url` | ?string | `null` | no | the webhook's URL as registered: its path is part of the signed string |
| `webview` | string | `https://webview.powens.com` | no | |
| `language` | ?string | `null` | no | the Webview's language, put in its path (`/fr/connect`) |

### `bridge`

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | |
| `client_secret` | string | - | yes | |
| `version` | string | `2025-01-15` | no | the `Bridge-Version` header |
| `webhook_secret` | ?string | `null` | for `notify()` | |
| `callback_url` | ?string | `null` | no | the return URL when `connect()` is given an empty one |
| `country_code` | ?string | `FR` | no | the banks a new Connect session lists (not sent for a renewal) |
| `host` | string | `https://api.bridgeapi.io` | no | |

## Timeouts

The API clients set their own timeouts per request: Qonto 20 seconds, Powens and Bridge 30. They
are constructor arguments of each `Api` class, not options of the factories. The HTTP client given
to the factory brings everything else (proxy, retries, a `MockHttpClient` in tests).
