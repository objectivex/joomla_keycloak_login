<?php

namespace Joomla\Plugin\System\Keycloakoauth\Extension;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

final class Keycloakoauth extends CMSPlugin implements SubscriberInterface
{
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

		$baseUrl = trim($this->getApplication()->getInput()->post->getString('base_url', ''));

		if (empty($baseUrl) || !filter_var($baseUrl, FILTER_VALIDATE_URL))
		{
			Log::add('KeycloakOAuth AJAX discovery called with invalid base_url: ' . $baseUrl, Log::WARNING, 'keycloakoauth');
			throw new \RuntimeException('Invalid base_url', 400);
		}

		$baseUrl      = rtrim($baseUrl, '/');
		$discoveryUrl = $baseUrl . '/.well-known/openid-configuration';

		Log::add('KeycloakOAuth discovery: ' . $discoveryUrl, Log::DEBUG, 'keycloakoauth');

		$http     = HttpFactory::getHttp();
		$response = $http->get($discoveryUrl);

		if ($response->code !== 200)
		{
			throw new \RuntimeException('Discovery request failed with HTTP ' . $response->code, 502);
		}

		$body = json_decode($response->body, true);

		if (!is_array($body))
		{
			throw new \RuntimeException('Invalid JSON from discovery endpoint', 502);
		}

		$results   = (array) $event->getArgument('result', []);
		$results[] = [
			'authorization_endpoint' => $body['authorization_endpoint'] ?? '',
			'token_endpoint'         => $body['token_endpoint']         ?? '',
			'userinfo_endpoint'      => $body['userinfo_endpoint']      ?? '',
		];
		$event->setArgument('result', $results);

		Log::add('KeycloakOAuth discovery successful', Log::DEBUG, 'keycloakoauth');
	}
}
