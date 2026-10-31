<?php

namespace Omnibank\Request;

use Omnibank\Model\Webhook;

/** The webhooks registered at the provider, without their secrets. Result: list<Webhook>. */
final class FetchWebhooks extends Request
{
    /** @return list<Webhook> */
    public function getWebhooks(): array
    {
        return $this->getResult() ?? [];
    }
}
