<?php

/**
 * Every provider package: its slug (omnibank/<slug>, github.com/glitchr-omnisense/omnibank-<slug>),
 * its tests' namespace and its factory class.
 */
return [
    'files' => ['Omnibank\\Files\\Tests\\', 'Omnibank\\Files\\FilesGatewayFactory'],
    'qonto' => ['Omnibank\\Qonto\\Tests\\', 'Omnibank\\Qonto\\QontoGatewayFactory'],
    'powens' => ['Omnibank\\Powens\\Tests\\', 'Omnibank\\Powens\\PowensGatewayFactory'],
    'bridge' => ['Omnibank\\Bridge\\Tests\\', 'Omnibank\\Bridge\\BridgeGatewayFactory'],
];
