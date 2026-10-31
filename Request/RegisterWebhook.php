<?php

namespace Omnibank\Request;

use Omnibank\Model\Webhook;

/**
 * Asks the provider to post its events to $url, signed with a secret it
 * makes - so that nobody copies a secret from a console. Optional: a
 * gateway that supports() it can do it, the others cannot (the provider
 * has no API for it). Registering an address already registered replaces
 * it: the provider's former webhook on that address is deleted, a new one
 * made, its secret new. $events empty: the events the provider's gateway
 * reads. Result: the Webhook, with its secret.
 */
final class RegisterWebhook extends Request
{
    /** @param list<string> $events */
    public function __construct(public readonly string $url, public readonly array $events = [])
    {
    }

    public function getWebhook(): ?Webhook
    {
        return $this->getResult();
    }
}
