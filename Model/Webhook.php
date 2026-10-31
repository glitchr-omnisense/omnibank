<?php

namespace Omnibank\Model;

/**
 * A webhook the application registered at the provider: the provider's id
 * for it, the address it posts to, the events it sends, and - only in the
 * answer to its registration, as providers give it once - the secret its
 * deliveries are signed with. Keep that secret sealed; never log it.
 */
final readonly class Webhook
{
    /** @param list<string> $events */
    public function __construct(
        public string $id,
        public string $url,
        public array $events = [],
        /** The signing secret: set in the answer to RegisterWebhook only. */
        #[\SensitiveParameter] public ?string $secret = null,
        public array $raw = [],
    ) {
    }
}
