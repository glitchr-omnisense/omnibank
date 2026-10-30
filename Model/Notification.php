<?php

namespace Omnibank\Model;

/**
 * What a provider is telling us, checked: one event about one connection.
 * $type is the provider's own name for it ("CONNECTION_SYNCED",
 * "item.refreshed"); $consent what it means for the user's consent;
 * $connectionId the provider's connection (an item, a Powens connection);
 * $state how that connection is doing, when the event says (null otherwise).
 */
final readonly class Notification
{
    public function __construct(
        public string $type,
        public ?string $connectionId,
        public Consent $consent,
        public array $raw,
        public ?ConnectionState $state = null,
    ) {
    }
}
