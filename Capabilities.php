<?php

namespace Omnibank;

use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Provider\CapabilitiesInterface;

/**
 * What a gateway does, said before it is asked anything: the operations it
 * supports (GatewayInterface's method names - "connect", "accounts",
 * "transactions"... - and "registerWebhook", "fetchWebhooks",
 * "deleteWebhook" for the requests without a shortcut) and its usage policy
 * as the provider declares it (its policy.json).
 */
final readonly class Capabilities implements CapabilitiesInterface
{
    /** @param list<string> $operations */
    public function __construct(
        public array $operations,
        private UsagePolicy $policy,
    ) {
    }

    /** @return list<string> */
    public function contracts(): array
    {
        return $this->operations;
    }

    public function supports(string $operation): bool
    {
        return \in_array($operation, $this->operations, true);
    }

    public function policy(): UsagePolicy
    {
        return $this->policy;
    }

    /** @return array{operations: list<string>, policy: array<string, mixed>} */
    public function toArray(): array
    {
        return ['operations' => $this->operations, 'policy' => $this->policy->toArray()];
    }
}
