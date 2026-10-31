<?php

namespace Omnibank\Tests;

use Omnibank\Http\PublicUrl;
use Omnibank\Model\Webhook;
use Omnibank\Request\DeleteWebhook;
use Omnibank\Request\FetchWebhooks;
use Omnibank\Request\RegisterWebhook;
use PHPUnit\Framework\TestCase;

/** The optional capability to register a webhook from the application, and the addresses a provider can reach. */
final class WebhookRegistrationTest extends TestCase
{
    public function testTheRequestsAndTheirResults(): void
    {
        $register = new RegisterWebhook('https://shop.example.org/webhook', ['a.b']);
        $webhook = new Webhook('we_1', 'https://shop.example.org/webhook', ['a.b'], 'whsec_not_a_real_secret');
        $register->setResult($webhook);
        self::assertSame($webhook, $register->getWebhook());
        self::assertSame('whsec_not_a_real_secret', $register->getWebhook()->secret);

        $fetch = new FetchWebhooks();
        self::assertSame([], $fetch->getWebhooks(), 'unanswered: none');
        $fetch->setResult([new Webhook('we_1', 'https://shop.example.org/webhook')]);
        self::assertNull($fetch->getWebhooks()[0]->secret, 'listed without its secret');

        self::assertSame('we_1', (new DeleteWebhook('we_1'))->id);
    }

    public function testOnlyAPublicHttpsAddressIsReachable(): void
    {
        self::assertNull(PublicUrl::unreachable('https://meyers-associee.fr/estate/payments/webhook'));
        self::assertNull(PublicUrl::unreachable('https://93.184.216.34/hook'));
        foreach (['https://localhost:8463/hook', 'https://meyers.local/hook', 'https://site.test/hook', 'https://192.168.1.10/hook', 'https://10.0.0.2/hook', 'https://127.0.0.1/hook', 'https://web/hook', 'http://meyers-associee.fr/hook', '/hook'] as $url) {
            self::assertNotNull(PublicUrl::unreachable($url), $url);
        }
    }
}
