<?php

\defined('_JEXEC') or die;

require_once __DIR__ . '/../src/Extension/Keycloakoauth.php';

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\Authentication\Keycloakoauth\Extension\Keycloakoauth;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$plugin = new Keycloakoauth(
					(array) PluginHelper::getPlugin('authentication', 'keycloakoauth')
				);

				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};