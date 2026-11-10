---
title: Symfony
order: 15
---

# Symfony

Omnibank runs without a framework ([installation](installation.md)); in a Symfony application its
bundle does the wiring. Its components - `symfony/config`, `symfony/dependency-injection`,
`symfony/http-kernel` - are not required by `omnisense/omnibank`: the application has them, and
nothing of them is loaded outside Symfony.

Register `Omnibank\Bridge\Symfony\OmnibankBundle` (no Flex recipe):

```php
// config/bundles.php
return [
    // ...
    Omnibank\Bridge\Symfony\OmnibankBundle::class => ['all' => true],
];
```

```yaml
# config/packages/omnibank.yaml
omnibank:
    gateways:                      # by name: a factory and its options
        statements: { factory: files, options: { debtor_name: 'Nakaya SAS', debtor_bic: AGRIFRPP882 } }
        treasury:   { factory: qonto, options: { login: '%env(QONTO_LOGIN)%', secret_key: '%env(QONTO_SECRET_KEY)%' } }
        banks:      { factory: powens, options: { domain: '%env(POWENS_DOMAIN)%', client_id: '%env(POWENS_CLIENT_ID)%', client_secret: '%env(POWENS_CLIENT_SECRET)%', webhook_secret: '%env(POWENS_WEBHOOK_SECRET)%' } }
```

```sh
# .env.local, or bin/console secrets:set
QONTO_LOGIN=...
QONTO_SECRET_KEY=...
```

## The configuration tree

As the bundle's `configure()` declares it (dumped with Symfony's `YamlReferenceDumper`, the dumper
`config:dump-reference omnibank` uses):

```yaml
omnibank:

    # The application's bank accesses, by name: a factory (files, qonto, powens, bridge...) and its options.
    gateways:

        # Prototype
        name:
            factory:              ~ # Required
            options:              []

            # A tightening of the provider's usage policy, in omnisense/contracts' schema (never looser).
            policy:               ~

            # What the site is: {commercial: true, open_source: false, contract: true}.
            usage:                ~

    # A service implementing Omnisense\Contracts\Policy\RateLimiterInterface, shared by the processes; null: in memory.
    limiter:              null
```

| Key | Type | Default | |
|---|---|---|---|
| `gateways.<name>.factory` | string | required | a factory's name: `files`, `qonto`, `powens`, `bridge`, or an application factory's `getName()` |
| `gateways.<name>.options` | any | `[]` | handed whole to the factory's `create()`; each provider's options are in [Configuration](configuration.md) |
| `gateways.<name>.policy` | map | none | a tightening of the provider's [usage policy](policies.md) |
| `gateways.<name>.usage` | map | none | what the site is: `commercial`, `open_source`, `contract` |
| `limiter` | service id | `null` | a `RateLimiterInterface` shared by the processes; in memory otherwise |

The bundle is the only class of the core that needs Symfony: `symfony/http-kernel`,
`symfony/dependency-injection` and `symfony/config` are suggested, not required.

## What the bundle registers

Every `omnibank/*` package installed registers its factory, on the application's `http_client`
(`service('http_client')->nullOnInvalid()`: without FrameworkBundle's HTTP client the factory gets
none and makes its own with `HttpClient::create()`), kept with its retries off (omnisense/contracts'
`Http\NoRetry`: `retry_failed` never sends a write twice).

| Service | | |
|---|---|---|
| `Omnibank\Files\FilesGatewayFactory`, `Omnibank\Qonto\QontoGatewayFactory`, `Omnibank\Powens\PowensGatewayFactory`, `Omnibank\Bridge\BridgeGatewayFactory` | private, tagged `omnibank.gateway_factory` | each when its package is installed |
| `Omnibank\Registry` | public | every service tagged `omnibank.gateway_factory`, the configured `gateways` |
| `omnibank.gateway.<name>` | private | `Registry::get('<name>')`, autowired as `GatewayInterface $<name>` |

Any service of the application implementing `Omnibank\GatewayFactoryInterface` is tagged
`omnibank.gateway_factory` by autoconfiguration. The bundle defines no command, event or Twig
function.

The services compiled for one gateway `statements` (`factory: files`), with the four providers
installed, and the gateway an argument `GatewayInterface $statements` receives:

```
Omnibank\Bridge\BridgeGatewayFactory
Omnibank\Files\FilesGatewayFactory
Omnibank\Powens\PowensGatewayFactory
Omnibank\Qonto\QontoGatewayFactory
Omnibank\Registry
Omnibank\Gateway files
files, qonto, powens, bridge
```

(run on 2026-10-26, Symfony 8 DependencyInjection, the family's checkouts; the last two lines are
the injected gateway's class and name, then `Registry::factories()`)

What is autowired:

| Service | |
|---|---|
| `GatewayInterface $treasury` | one gateway by the argument's name (the configured name) |
| `Registry` | every configured gateway by name (`get()`, `has()`, `names()`, `create()` with other options) |

```php
public function __construct(private readonly GatewayInterface $treasury)
{
}
```

Nothing is built when the container compiles: a gateway is built the first time it is asked
for, and an option left empty only shows then (`InvalidConfigException`).

An application's own provider - a class implementing `GatewayFactoryInterface` - is registered
too, autoconfigured, and can be named as a `factory`.

```php
final class BankWebhookController extends AbstractController
{
    #[Route('/bank/webhook', methods: ['POST'])]
    public function __invoke(Request $request, GatewayInterface $banks): Response
    {
        try {
            $notification = $banks->notify($request->getContent(), $request->headers->all());
        } catch (InvalidNotificationException) {
            return new Response(status: 400);
        }
        // $notification->connectionId, ->consent: fetch again, or ask the user to renew

        return new Response(status: 204);
    }
}
```
