<?php

namespace Omnibank;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Exception\UsageLimitReachedException;
use Omnibank\Http\Redaction;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Http\NoRetry;
use Omnisense\Contracts\Provider\Config as BaseConfig;
use Omnisense\Contracts\Provider\ProviderFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Omnibus way: a provider's factory fills a Config - its name, title,
 * the options it needs, its API client ("omnibank.api", a closure of the
 * Config), its actions ("omnibank.action.<name>"), its usage policy
 * ("omnibank.policy": the path of its policy.json, or a UsagePolicy) and the
 * key of a connection's state that names the provider's user
 * ("omnibank.user_key", for the limits counted per user) - and the gateway
 * is those actions, the API handed to the ones that ask for it.
 *
 * It is omnisense/contracts' ProviderFactory: before the API is built, the
 * Config holds the provider's Backoff (Config::backoff(), over the
 * registry's limiter), which the API observes on every answer
 * (Http\Throttle); guard() gives the gateway's PolicyGuard, its declared
 * policy tightened by the "policy" option and checked against "usage"
 * (Registry::guarded() puts the gateway behind it). The HTTP client given
 * is kept with its retries off (omnisense/contracts' Http\NoRetry): Symfony's
 * RetryableHttpClient never creates a user or a connect session twice.
 */
abstract class GatewayFactory extends ProviderFactory implements GatewayFactoryInterface
{
    protected readonly ?HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        // Http\NoRetry came with omnisense/contracts 1568f2c: a site whose vendor holds an older one keeps its client as given.
        $this->http = null !== $http && class_exists(NoRetry::class) ? NoRetry::of($http) : $http;
    }

    public function create(array $options = []): GatewayInterface
    {
        try {
            $gateway = parent::create($options);
        } catch (\Throwable $e) {
            // The options hold the credentials: no trace of the failure keeps them in its arguments.
            throw Redaction::withoutArguments($e);
        }

        return $gateway instanceof GatewayInterface ? $gateway : throw new \LogicException(static::class.' built no gateway.');
    }

    public function createConfig(array $options = []): Config
    {
        $config = parent::createConfig($options);

        return $config instanceof Config ? $config : throw new \LogicException('An omnibank factory fills an Omnibank\Config.');
    }

    abstract protected function populateConfig(Config $config): void;

    protected function newConfig(array $options): Config
    {
        return new Config($options);
    }

    protected function configure(BaseConfig $c): void
    {
        \assert($c instanceof Config);
        $this->populateConfig($c);
    }

    protected function make(BaseConfig $c): GatewayInterface
    {
        \assert($c instanceof Config);
        $api = $c->get('omnibank.api');
        if ($api instanceof \Closure) {
            $api = $api($c);
        }

        $actions = [];
        foreach ($c as $key => $action) {
            if (!str_starts_with((string) $key, 'omnibank.action.')) {
                continue;
            }
            if ($action instanceof \Closure) {
                $action = $action($c);
            }
            if (!$action instanceof ActionInterface) {
                continue;
            }
            if ($action instanceof ApiAwareInterface && \is_object($api)) {
                $action->setApi($api);
            }
            $actions[] = $action;
        }
        $name = $c->string('omnibank.factory_name') ?? static::class;

        return new Gateway($name, $c->string('omnibank.factory_title') ?? $name, $actions, self::policy($c->get('omnibank.policy'), $name), null, $c->string('omnibank.user_key'));
    }

    protected function limitException(): string
    {
        return UsageLimitReachedException::class;
    }

    /** The provider's declared policy: a UsagePolicy, the path of a policy.json, or unknown. */
    private static function policy(mixed $policy, string $name): UsagePolicy
    {
        return match (true) {
            $policy instanceof UsagePolicy => $policy,
            \is_string($policy) && '' !== $policy => UsagePolicy::fromFile($policy),
            default => UsagePolicy::unknown($name),
        };
    }
}
