<?php

namespace Omnibank;

use Omnibank\Action\ActionInterface;
use Omnibank\Action\ApiAwareInterface;
use Omnibank\Http\Redaction;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Omnibus way: a provider's factory fills a Config - its name, title,
 * the options it needs, its API client ("omnibank.api", a closure of the
 * Config) and its actions ("omnibank.action.<name>") - and the gateway is
 * those actions, the API handed to the ones that ask for it.
 */
abstract class GatewayFactory implements GatewayFactoryInterface
{
    public function __construct(protected readonly ?HttpClientInterface $http = null)
    {
    }

    public function getName(): string
    {
        return $this->createConfig()['omnibank.factory_name'];
    }

    public function create(array $options = []): GatewayInterface
    {
        try {
            $config = $this->createConfig($options);
            $config->validateNotEmpty($config->get('omnibank.required_options', []));

            $api = $config->get('omnibank.api');
            if ($api instanceof \Closure) {
                $api = $api($config);
            }

            $actions = [];
            foreach ($config as $key => $action) {
                if (!str_starts_with((string) $key, 'omnibank.action.')) {
                    continue;
                }
                if ($action instanceof \Closure) {
                    $action = $action($config);
                }
                if (!$action instanceof ActionInterface) {
                    continue;
                }
                if ($action instanceof ApiAwareInterface && null !== $api) {
                    $action->setApi($api);
                }
                $actions[] = $action;
            }

            return new Gateway($config['omnibank.factory_name'], $config['omnibank.factory_title'], $actions);
        } catch (\Throwable $e) {
            // The options hold the credentials: no trace of the failure keeps them in its arguments.
            throw Redaction::withoutArguments($e);
        }
    }

    public function createConfig(array $options = []): Config
    {
        $config = new Config($options);
        $this->populateConfig($config);

        return $config;
    }

    abstract protected function populateConfig(Config $config): void;
}
