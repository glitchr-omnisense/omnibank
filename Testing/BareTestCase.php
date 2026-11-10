<?php

namespace Omnibank\Testing;

use Omnibank\Registry;

/**
 * omnisense/contracts' bare check for an omnibank package: each of its
 * classes loaded in a PHP process of its own, with nothing from outside but
 * omnisense/contracts, omnisense/omnibank's core and what the package
 * requires - no framework, no application. A provider's test names its entry
 * class and the packages it requires:
 *
 *   final class LoadAllTest extends \Omnibank\Testing\BareTestCase
 *   {
 *       protected static function entryClass(): string { return QontoGatewayFactory::class; }
 *       protected static function requiredPackages(): array { return ['symfony/http-client-contracts']; }
 *   }
 *
 * A class over a suggested package says so ("@suggested <package>") and is
 * not loaded: the core's Symfony bundle.
 *
 * @suggested phpunit/phpunit (test support: loaded by a package's tests only)
 */
abstract class BareTestCase extends \Omnisense\Contracts\Testing\BareTestCase
{
    /** @var non-empty-string */
    protected const AUTOLOAD_ENV = 'OMNIBANK_AUTOLOAD';

    protected static function coreDirs(): array
    {
        return [...parent::coreDirs(), self::dirOf(Registry::class)];
    }
}
