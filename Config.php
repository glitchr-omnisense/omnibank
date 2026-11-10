<?php

namespace Omnibank;

use Omnibank\Exception\InvalidConfigException;

/**
 * A gateway's configuration while its factory builds it: the options given,
 * the factory's defaults under them, and the keys that must be filled -
 * omnisense/contracts' Config, with omnibank's prefix ("omnibank.factory_name",
 * "omnibank.backoff"...) and its InvalidConfigException. Every gateway takes
 * two options besides its own: "policy" (the site's tightening of its usage
 * policy) and "usage" (what the site says of itself: commercial,
 * open_source, contract).
 */
final class Config extends \Omnisense\Contracts\Provider\Config
{
    /** @var string */
    public const PREFIX = 'omnibank';

    /** @param list<string> $keys */
    public function validateNotEmpty(array $keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $key) => !isset($this[$key]) || '' === $this[$key] || [] === $this[$key]));
        if ($missing) {
            throw new InvalidConfigException(\sprintf('The "%s" gateway needs: %s.', $this->string('omnibank.factory_name') ?? '?', implode(', ', $missing)));
        }
    }

    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException($message);
    }
}
