---
title: Laravel
order: 16
---

# Laravel

There is no Laravel bridge for Omnibank: omnibridge/laravel has none, and this package ships no
service provider. None is needed for it to run - the core needs no framework - so a service
provider of the application builds the registry once, by hand, as in plain PHP.

## A service provider

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Omnibank\Files\FilesGatewayFactory;
use Omnibank\GatewayInterface;
use Omnibank\Powens\PowensGatewayFactory;
use Omnibank\Qonto\QontoGatewayFactory;
use Omnibank\Registry;
use Symfony\Component\HttpClient\HttpClient;

final class BankServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class, function () {
            $http = HttpClient::create();

            return new Registry(
                [new FilesGatewayFactory(), new QontoGatewayFactory($http), new PowensGatewayFactory($http)],
                [
                    'statements' => ['factory' => 'files', 'options' => ['debtor_name' => config('bank.debtor_name')]],
                    'treasury' => ['factory' => 'qonto', 'options' => ['login' => config('services.qonto.login'), 'secret_key' => config('services.qonto.secret_key')]],
                    'banks' => ['factory' => 'powens', 'options' => [
                        'domain' => config('services.powens.domain'),
                        'client_id' => config('services.powens.client_id'),
                        'client_secret' => config('services.powens.client_secret'),
                        'webhook_secret' => config('services.powens.webhook_secret'),
                        'webhook_url' => route('bank.webhook'),
                    ]],
                ],
            );
        });

        // One gateway where a class asks for a GatewayInterface
        $this->app->when(\App\Http\Controllers\TreasuryController::class)
            ->needs(GatewayInterface::class)
            ->give(fn ($app) => $app->make(Registry::class)->get('treasury'));
    }
}
```

The registry builds each gateway the first time it is asked for, so a missing option shows then
(`InvalidConfigException`), not at boot.

## A webhook route

```php
// routes/api.php: no session, no CSRF check on a provider's POST
use Illuminate\Http\Request;
use Omnibank\Exception\InvalidNotificationException;
use Omnibank\Registry;

Route::post('/bank/webhook', function (Request $request, Registry $registry) {
    try {
        $notification = $registry->get('banks')->notify($request->getContent(), $request->headers->all());
    } catch (InvalidNotificationException) {
        return response()->noContent(400);
    }
    // $notification->connectionId, $notification->consent

    return response()->noContent();
})->name('bank.webhook');
```

The body must be the raw one (`getContent()`): the signature is computed over its bytes.
`$request->headers->all()` gives each header as a list, which `Request\Notify::header()` reads
whatever its case.
