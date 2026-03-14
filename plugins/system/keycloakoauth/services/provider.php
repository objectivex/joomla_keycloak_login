<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\System\Keycloakoauth\Extension\Keycloakoauth;

return new class () implements ServiceProviderInterface {
	/**
	 * Registers the service provider with a DI container.
	 * Wird von Joomla automatisch aufgerufen
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$plugin = new Keycloakoauth(
					(array) PluginHelper::getPlugin('system', 'keycloakoauth')
				);

				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};
