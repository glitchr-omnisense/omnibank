<?php

namespace Omnibank\Tests;

use Omnibank\Registry;
use Omnibank\Testing\BareTestCase;

/**
 * Every class of the core loads with omnisense/contracts and Symfony's HTTP
 * client contracts alone - the Symfony bundle (@suggested symfony/http-kernel)
 * left out, as it needs what the core only suggests.
 */
final class LoadAllTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return Registry::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts', 'symfony/deprecation-contracts', 'psr/container'];
    }
}
