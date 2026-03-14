<?php

namespace Joomla\Plugin\System\Keycloakoauth\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Log\Log;
use Joomla\Plugin\System\Keycloakoauth\Service\KeycloakService;

\defined('_JEXEC') or die;

final class Keycloakoauth extends CMSPlugin implements SubscriberInterface
{
	private ?KeycloakService $keycloakService = null;

	/**
	 * Sprachdatei automatisch laden
	 *
	 * @var boolean
	 * @since 1.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Gibt alle Events zurück, auf die dieses Plugin hört
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onApplicationInitialise' => 'onApplicationInitialise',
			'onAjaxKeycloakoauth'     => 'onAjaxKeycloakoauth',
		];
	}

	/**
	 * Application initialisation Event
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function onApplicationInitialise(): void
	{
		Log::add('KeycloakOAuth initialized', Log::DEBUG, 'joomla');
	}

	/**
	 * AJAX-Handler: führt die OpenID-Connect-Discovery server-seitig aus
	 *
	 * @return array
	 * @since  1.0.0
	 */
	public function onAjaxKeycloakoauth(Event $event): void
	{
		Log::add('KeycloakOAuth AJAX discovery called', Log::DEBUG, 'keycloakoauth');

		if (!Session::checkToken())
		{
			Log::add('KeycloakOAuth AJAX discovery called with invalid CSRF token', Log::WARNING, 'keycloakoauth');
			throw new \RuntimeException('Invalid CSRF token', 403);
		}

		$app  = $this->getApplication();
		$user = $app->getIdentity();

		// Ensure that only privileged users in the administrator application can perform discovery
		if (!$app->isClient('administrator') || !$user->authorise('core.admin'))
		{
			Log::add('KeycloakOAuth AJAX discovery called without sufficient permissions', Log::WARNING, 'keycloakoauth');
			throw new \RuntimeException('Not authorized', 403);
		}

		$baseUrl = $app->getInput()->post->getString('base_url', '');

		$service = $this->getKeycloakService();
		Log::add('KeycloakOAuth discovery: ' . $service->getDiscoveryUrl($baseUrl), Log::DEBUG, 'keycloakoauth');

		$body = $service->discoverEndpoints($baseUrl);

		$results   = (array) $event->getArgument('result', []);
		$results[] = [
			'authorization_endpoint' => $body['authorization_endpoint'],
			'token_endpoint'         => $body['token_endpoint'],
			'userinfo_endpoint'      => $body['userinfo_endpoint'],
		];
		$event->setArgument('result', $results);

		Log::add('KeycloakOAuth discovery successful', Log::DEBUG, 'keycloakoauth');
	}

	private function getKeycloakService(): KeycloakService
	{
		if ($this->keycloakService === null)
		{
			$this->keycloakService = new KeycloakService();
		}

		return $this->keycloakService;
	}
}
