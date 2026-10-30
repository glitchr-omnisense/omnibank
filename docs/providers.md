---
title: Writing a provider
order: 80
---

# Writing a provider

A provider is a package `omnibank/<name>`, namespace `Omnibank\<Name>\`, that requires
`omnisense/omnibank` (and `symfony/http-client` when it calls an API). It holds:

- a **factory**, extending `Omnibank\GatewayFactory`, that fills the `Config`;
- an **API client** (`Api`), the one class that talks to the service: its host, its
  authentication, its errors turned into the family's exceptions;
- one **action** per request it answers, `Action\<Request>Action`, implementing `ActionInterface`
  (and `ApiAwareInterface` with `ApiAwareTrait` when it needs the API);
- a mapper from the service's answers to the models, and tests on recorded answers.

## The factory

```php
namespace Omnibank\Acme;

use Omnibank\Acme\Action\AccountsAction;
use Omnibank\Acme\Action\ConnectAction;
use Omnibank\Config;
use Omnibank\GatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

final class AcmeGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibank.factory_name' => 'acme',
            'omnibank.factory_title' => 'Acme Bank',
            'omnibank.required_options' => ['api_key'],
            'sandbox' => false,
            'omnibank.api' => fn (Config $c) => new Api($this->http ?? HttpClient::create(), (string) $c['api_key'], $c['sandbox'] ? Api::SANDBOX : Api::HOST),
            'omnibank.action.connect' => new ConnectAction(),
            'omnibank.action.accounts' => new AccountsAction(),
        ]);
    }
}
```

`defaults()` sets only the keys the application did not give: an option the application sets wins,
`omnibank.*` keys included. An action that needs an option is a closure of the `Config`
(`static fn (Config $c) => new ConnectAction($c['redirect_uri'] ?: null)`).

## An action

```php
namespace Omnibank\Acme\Action;

use Omnibank\Acme\Api;
use Omnibank\Action\{ActionInterface, ApiAwareInterface, ApiAwareTrait};
use Omnibank\Model\Account;
use Omnibank\Request\{FetchAccounts, Request};

final class AccountsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchAccounts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchAccounts);
        $request->setResult(array_map(
            static fn (array $a) => new Account((string) $a['id'], $a['iban'] ?? null, $a['bic'] ?? null, (string) $a['name'], strtoupper((string) $a['currency']), $a['type'] ?? null, $a),
            $this->api->get('/accounts')['accounts'] ?? [],
        ));
    }
}
```

`supports()` must answer from the request's class alone: `Gateway::supports()` asks it with a probe
made without the constructor, whose properties are not set.

## The rules every provider follows

- **Signed amounts.** `BankTransaction::$amount` is negative when money leaves the account, in
  minor units (`Money::fromDecimal()` from a decimal string, never through a float when the service
  gives a string).
- **Booked lines only, oldest first,** filtered by `FetchTransactions::covers()`, every page read.
- **Stable ids.** The service's id, or a fingerprint of the line (`Omnibank\Files\Fingerprints` is
  one way).
- **`raw` keeps the service's whole answer** for the account and each line.
- **The service's words, mapped.** An account's type onto `AccountKind` (`Account::$kind`, the word
  kept in `$type`), a line's category or operation type onto `TransactionCategory`, the
  connection's states onto `ConnectionState` (`Notification::$state`, `Account::$state` when the
  service says it with the account, `ProviderException::$connectionState` when an error code is
  one), each from the service's published documentation, as public constants a test runs through.
  A word not in the table is `UNKNOWN` (no category at all is null), never an exception.
- **Errors.** A 4xx is a `ProviderException` (the service's message, its code as `providerCode`); a
  transport error, a 5xx, a 429 or a body that is not JSON is an `UnavailableException`, never an
  empty list; a missing option an `InvalidConfigException` (`omnibank.required_options`).
- **Webhooks.** `notify()` checks the signature with `hash_equals()` before reading anything, and
  throws `InvalidNotificationException` without a secret or a signature.
- **Tokens stay home.** A pagination link to another host is not followed (Powens' and Bridge's
  `Api::call()` refuse it); what the application must keep goes in the returned `Connection`'s
  state, nowhere else.

## Registering it

- In the harness: a line in `docker/harness/plugins.php` (slug, tests' namespace, factory class) and
  its options in `docker/harness/config/gateways.php`.
- In Symfony: `OmnibankBundle::FACTORIES` lists the four known factories; another package's (or the
  application's) factory is registered when it is a service, autoconfigured with the tag
  `omnibank.gateway_factory`.
- Its README: what the service is, its options, its credentials, what is verified and not, and a
  Responsibility section naming the service's terms with a link.

There is no conformance suite in the family yet: a provider's tests check each request on recorded
answers, an outage as `UnavailableException`, and webhooks signed and forged (the four providers'
`Tests/*GatewayTest.php` are the models).
