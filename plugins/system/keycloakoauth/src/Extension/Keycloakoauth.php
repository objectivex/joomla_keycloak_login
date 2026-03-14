<?php

namespace Joomla\Plugin\System\Keycloakoauth\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Log\Log;

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
			'onAjaxKeycloakoauth' => 'onAjaxKeycloakoauth',
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
	 * AJAX-Handler: schreibt eine Debug-Nachricht ins Joomla-Log
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function onAjaxKeycloakoauth(): void
	{
		if (!Session::checkToken('get')) {
			throw new \RuntimeException('Invalid CSRF token', 403);
		}

		Log::add('KeycloakOAuth discover endpoints button clicked', Log::DEBUG, 'keycloakoauth');
	}
}
