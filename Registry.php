<?php

namespace Omnibank;

use Omnibank\Exception\InvalidConfigException;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Provider\ProviderFactoryInterface;
use Omnisense\Contracts\Provider\Registry as BaseRegistry;

/**
 * The application's bank accesses by name, each built once from its factory
 * and options:
 *
 *   new Registry([new QontoGatewayFactory($http)], [
 *       'treasury' => ['factory' => 'qonto', 'options' => [...]],
 *   ]);
 *
 * omnisense/contracts' Registry: each gateway's calls go through its usage
 * policy (the provider's policy.json), which a gateway's "policy" tightens
 * (never looser) and its "usage" checks (what the site is: commercial,
 * open_source, contract) - both beside "options" or in them. policy(),
 * policies() and guard() give them; guarded() gives the gateway behind its
 * guard, each request refused before it is sent once a limit is reached
 * (UsageLimitReachedException), while get() gives it as before, unguarded.
 * Either way the waits a provider asks for (a 429's Retry-After, a RateLimit
 * field with nothing left) hold its calls. Every gateway counts in the
 * limiter given as the third argument (in memory by default): an
 * application served by many processes gives a shared one, so that a wait
 * holds in all of them.
 */
final class Registry extends BaseRegistry
{
    /** @var string */
    public const PREFIX = Config::PREFIX;

    /** @var array<string, GatewayInterface> */
    private array $guardedGateways = [];

    /**
     * @param iterable<GatewayFactoryInterface>                                                                                          $factories
     * @param array<string, array{factory: string, options?: array<string, mixed>, policy?: array<string, mixed>, usage?: array<string, mixed>}> $config
     */
    public function __construct(iterable $factories, array $config, ?RateLimiterInterface $limiter = null)
    {
        parent::__construct($factories, $config, $limiter);
    }

    public function get(string $name): GatewayInterface
    {
        $gateway = parent::get($name);

        return $gateway instanceof GatewayInterface ? $gateway : throw new InvalidConfigException(\sprintf('The "%s" gateway (%s) is no omnibank gateway.', $name, $gateway::class));
    }

    /**
     * The gateway behind its usage policy, as the site runs it: every request
     * but a webhook's checked against the limits that count its operation
     * ("connect", "transactions"... - Gateway::OPERATIONS) before it is sent,
     * counted after. Built once.
     *
     * @throws \Omnisense\Contracts\Policy\UsageNotPermitted when the gateway's "usage" says a use its policy forbids
     */
    public function guarded(string $name): GatewayInterface
    {
        if (isset($this->guardedGateways[$name])) {
            return $this->guardedGateways[$name];
        }
        $gateway = $this->get($name);

        return $this->guardedGateways[$name] = $gateway instanceof Gateway ? $gateway->guardedBy($this->guard($name)) : $gateway;
    }

    /**
     * A gateway built afresh, its configured options with $overrides over
     * them - keys typed in a back office, say. Not kept: get() still gives
     * the configured one.
     *
     * @param array<string, mixed> $overrides
     */
    public function create(string $name, array $overrides = []): GatewayInterface
    {
        $gateway = $this->factory($name)->create(array_replace($this->options($name), $overrides));

        return $gateway instanceof GatewayInterface ? $gateway : throw new InvalidConfigException(\sprintf('The "%s" gateway (%s) is no omnibank gateway.', $name, $gateway::class));
    }

    /** @return array<string, GatewayInterface> */
    public function all(): array
    {
        $all = [];
        foreach ($this->names() as $name) {
            $all[$name] = $this->get($name);
        }

        return $all;
    }

    protected function factory(string $name): ProviderFactoryInterface
    {
        $gateway = $this->config[$name] ?? throw new InvalidConfigException(\sprintf('No "%s" gateway; configured: %s.', $name, implode(', ', array_keys($this->config)) ?: 'none'));

        return $this->factories[$gateway['factory']] ?? throw new InvalidConfigException(\sprintf('No "%s" factory for the "%s" gateway; installed: %s.', $gateway['factory'], $name, implode(', ', array_keys($this->factories)) ?: 'none'));
    }

    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException($message);
    }
}
