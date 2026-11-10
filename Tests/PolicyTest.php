<?php

namespace Omnibank\Tests;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Action\ApiAwareTrait;
use Omnibank\Config;
use Omnibank\Exception\OmnibankException;
use Omnibank\Exception\RateLimitedException;
use Omnibank\Exception\UnavailableException;
use Omnibank\Exception\UsageLimitReachedException;
use Omnibank\GatewayFactory;
use Omnibank\Http\Throttle;
use Omnibank\Model\Account;
use Omnibank\Model\ConnectResult;
use Omnibank\Model\Connection;
use Omnibank\Model\Consent;
use Omnibank\Model\ConsentStatus;
use Omnibank\Model\Notification;
use Omnibank\Registry;
use Omnibank\Request\Connect;
use Omnibank\Request\FetchAccounts;
use Omnibank\Request\Notify;
use Omnibank\Request\Request;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * omnibank on omnisense/contracts' usage policies: a provider's declared
 * policy, a site's tightening, the guard Registry::guarded() puts in front of
 * a gateway (a limit per user counted per the provider's user), and what a
 * 429 does to a write - surfaced, never retried, the next calls held without
 * being sent.
 */
final class PolicyTest extends TestCase
{
    /** @var list<string> method and URL of each request sent */
    private array $sent = [];

    /** @var list<MockResponse> the answers, in order */
    private array $answers = [];

    public function testAGatewayDeclaresItsPolicyAndItsOperations(): void
    {
        $registry = $this->registry();
        $gateway = $registry->get('bank');

        self::assertSame('policied', $gateway->capabilities()->policy()->provider);
        self::assertSame(['connect', 'accounts', 'notify'], $gateway->capabilities()->contracts());
        self::assertSame(['bank'], array_keys($registry->policies()));
        self::assertSame(3, $registry->policy('bank')->limitsFor('accounts')[0]->max);
    }

    public function testAGuardedReadOverTheLimitIsNeverSent(): void
    {
        $registry = $this->registry();
        $this->answers = array_fill(0, 3, self::accounts());
        $guarded = $registry->guarded('bank');
        for ($i = 1; $i <= 3; ++$i) {
            $guarded->accounts(self::connection('u1'));
        }

        try {
            $guarded->accounts(self::connection('u1'));
            self::fail('a fourth read within the minute');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnibankException::class, $e, 'one catch holds the family\'s exceptions');
            self::assertGreaterThan(0, $e->retryAfter);
        }
        self::assertCount(3, $this->sent, 'the fourth was not sent');
        self::assertSame($guarded, $registry->guarded('bank'), 'built once');
        self::assertNotSame($guarded, $registry->get('bank'), 'get() gives the gateway unguarded');
        $registry->get('bank')->accounts(self::connection('u1'));
        self::assertCount(4, $this->sent, 'unguarded, as before');
    }

    public function testALimitPerUserCountsEachUserApartAndANewConnectionAsANewUser(): void
    {
        $registry = $this->registry();
        $this->answers = [self::created(), self::created(), self::created()];
        $guarded = $registry->guarded('bank');

        $guarded->connect(self::connection('u1'), 'https://app.example/back');
        try {
            $guarded->connect(self::connection('u1'), 'https://app.example/back');
            self::fail('one connect session a day for u1');
        } catch (UsageLimitReachedException) {
        }
        $guarded->connect(self::connection('u2'), 'https://app.example/back');
        $guarded->connect(new Connection(), 'https://app.example/back');
        self::assertCount(3, $this->sent, 'u1 once, u2 once, a new connection once');
    }

    public function testASiteTightensThePolicyButNeverLoosensIt(): void
    {
        $tight = $this->registry(['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['accounts']]]]);
        $this->answers = [self::accounts()];
        $tight->guarded('bank')->accounts(self::connection('u1'));
        try {
            $tight->guarded('bank')->accounts(self::connection('u1'));
            self::fail('the site allows one a minute');
        } catch (UsageLimitReachedException) {
        }
        self::assertCount(1, $this->sent);

        $this->expectException(InvalidPolicyException::class);
        $this->registry(['rateLimits' => [['max' => 10, 'window' => 'minute', 'operations' => ['accounts']]]])->policy('bank');
    }

    /**
     * A 429 to a write (a user created at the provider): an UnavailableException as a 429 always
     * was, with the wait asked, the request sent once and not again, the next call held without
     * being sent.
     */
    public function testA429ToAWriteIsSurfacedNeverRetriedAndHoldsTheNextCalls(): void
    {
        $registry = $this->registry([], new InMemoryRateLimiter());
        $this->answers = [new MockResponse('{"error":"rate_limited"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30']])];
        $gateway = $registry->get('bank');

        try {
            $gateway->connect(new Connection(), 'https://app.example/back');
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertInstanceOf(UnavailableException::class, $e, 'existing catches still hold it');
            self::assertSame(30, $e->retryAfter);
            self::assertSame('429', $e->providerCode);
        }
        self::assertSame(['POST https://bank.example/users'], $this->sent, 'sent once, not retried');

        try {
            $gateway->accounts(self::connection('u1'));
            self::fail('held while the wait lasts');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThan(0, $e->retryAfter);
        }
        self::assertCount(1, $this->sent, 'the held call was not sent');
    }

    /**
     * The application's http_client with retry_failed on: Symfony's RetryableHttpClient would
     * create the user again on a 503. The factory keeps its retries off: sent once.
     */
    public function testAWriteAnswered503IsSentOnceThroughARetryingClient(): void
    {
        $this->answers = [new MockResponse('{"error":"unavailable"}', ['http_code' => 503]), self::created()];
        $retrying = new RetryableHttpClient($this->http(), new GenericRetryStrategy(GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES, 0), 3);
        $gateway = (new PoliciedFactory($retrying))->create(['token' => 't']);

        $gateway->connect(new Connection(), 'https://app.example/back');
        self::assertSame(['POST https://bank.example/users'], $this->sent, 'the 503 was not retried by the client');
        self::assertCount(1, $this->answers, 'the second answer was never asked for');
    }

    public function testAMockHttpClientIsKeptAsGiven(): void
    {
        $mock = new MockHttpClient([self::accounts()]);
        $this->registryOver($mock)->get('bank')->accounts(self::connection('u1'));

        self::assertSame(1, $mock->getRequestsCount());
    }

    public function testAWebhookIsNeverHeldByTheGuard(): void
    {
        $registry = $this->registry(['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['notify']]]]);

        $registry->guarded('bank')->notify('{}');
        $registry->guarded('bank')->notify('{}');
        self::assertCount(0, $this->sent);
    }

    public function testThrottleWithoutABackoffStillRefusesA429(): void
    {
        Throttle::before(null);
        Throttle::after(null, 'policied', 200, []);
        $this->expectException(RateLimitedException::class);
        Throttle::after(null, 'policied', 429, []);
    }

    /** @param array<string, mixed> $policy */
    private function registry(array $policy = [], ?InMemoryRateLimiter $limiter = null): Registry
    {
        return new Registry([new PoliciedFactory($this->http())], ['bank' => ['factory' => 'policied', 'options' => ['token' => 't'], 'policy' => $policy]], $limiter);
    }

    private function registryOver(HttpClientInterface $http): Registry
    {
        return new Registry([new PoliciedFactory($http)], ['bank' => ['factory' => 'policied', 'options' => ['token' => 't']]]);
    }

    private function http(): HttpClientInterface
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->sent[] = $method.' '.$url;

            return array_shift($this->answers) ?? new MockResponse('{}', ['http_code' => 500]);
        });
    }

    private static function connection(string $user): Connection
    {
        return new Connection(['user' => $user]);
    }

    private static function accounts(): MockResponse
    {
        return new MockResponse('{"accounts":[{"id":"a1"}]}');
    }

    private static function created(): MockResponse
    {
        return new MockResponse('{"user":"u9"}');
    }
}

/**
 * A provider over HTTP whose policy allows 3 reads a minute and one connect session a day per
 * user, its client honouring the provider's waits.
 */
final class PoliciedFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibank.factory_name' => 'policied',
            'omnibank.factory_title' => 'Policied',
            'omnibank.user_key' => 'user',
            'omnibank.policy' => UsagePolicy::fromArray(['provider' => 'policied', 'rateLimits' => [
                ['max' => 3, 'window' => 'minute', 'operations' => ['accounts']],
                ['max' => 1, 'window' => 'day', 'scope' => 'user', 'operations' => ['connect']],
            ]]),
            'omnibank.api' => fn (Config $c) => new PoliciedApi($this->http ?? new MockHttpClient(), $c->backoff()),
            'omnibank.action.connect' => new PoliciedConnectAction(),
            'omnibank.action.accounts' => new PoliciedAccountsAction(),
            'omnibank.action.notify' => new PoliciedNotifyAction(),
        ]);
    }
}

final class PoliciedApi
{
    public function __construct(private readonly HttpClientInterface $http, private readonly ?Backoff $backoff)
    {
    }

    /** @return array<mixed> */
    public function call(string $method, string $path): array
    {
        Throttle::before($this->backoff);
        $response = $this->http->request($method, 'https://bank.example'.$path);
        Throttle::after($this->backoff, 'policied', $response->getStatusCode(), $response->getHeaders(false));

        return $response->toArray(false);
    }
}

final class PoliciedConnectAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<PoliciedApi> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = PoliciedApi::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Connect;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Connect);
        $user = $this->api->call('POST', '/users')['user'] ?? null;
        $request->setResult(new ConnectResult('https://bank.example/connect', $request->connection->withState(['user' => \is_string($user) ? $user : null])));
    }
}

final class PoliciedAccountsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<PoliciedApi> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = PoliciedApi::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchAccounts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchAccounts);
        $this->api->call('GET', '/accounts');
        $request->setResult([new Account('a1', null, null, 'Compte', 'EUR', null)]);
    }
}

final class PoliciedNotifyAction implements ActionInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $request->setResult(new Notification('ping', null, new Consent(ConsentStatus::NONE), []));
    }
}
