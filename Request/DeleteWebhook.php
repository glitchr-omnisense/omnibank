<?php

namespace Omnibank\Request;

/** Removes a webhook at the provider, by its id. Result: true (also when it was already gone). */
final class DeleteWebhook extends Request
{
    public function __construct(public readonly string $id)
    {
    }
}
