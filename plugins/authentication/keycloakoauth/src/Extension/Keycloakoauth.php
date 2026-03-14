<?php

namespace Joomla\Plugin\Authentication\Keycloakoauth\Extension;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Event\User\AuthenticationEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\Keycloakoauth\Service\AdminLoginSessionService;

\defined('_JEXEC') or die;

final class Keycloakoauth extends CMSPlugin implements SubscriberInterface
{
	private ?AdminLoginSessionService $adminLoginSessionService = null;

	protected $autoloadLanguage = true;

	public static function getSubscribedEvents(): array
	{
		return [
			'onUserAuthenticate' => 'onUserAuthenticate',
		];
	}

	public function onUserAuthenticate(AuthenticationEvent $event): void
	{
		$credentials = $event->getCredentials();
		$token = trim((string) ($credentials['keycloakoauth_token'] ?? ''));

		if ($token === '')
		{
			return;
		}

		$response = $event->getAuthenticationResponse();
		$payload = $this->getAdminLoginSessionService()->consumePendingLogin($token);

		if ($payload === null)
		{
			$response->status = Authentication::STATUS_FAILURE;
			$response->error_message = Text::_('PLG_AUTHENTICATION_KEYCLOAKOAUTH_ERROR_INVALID_TOKEN');
			return;
		}

		$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $payload['user_id']);

		if ((int) $user->id <= 0)
		{
			$response->status = Authentication::STATUS_FAILURE;
			$response->error_message = Text::_('PLG_AUTHENTICATION_KEYCLOAKOAUTH_ERROR_USER_NOT_FOUND');
			return;
		}

		if ((int) $user->block === 1)
		{
			$response->status = Authentication::STATUS_FAILURE;
			$response->error_message = Text::_('PLG_AUTHENTICATION_KEYCLOAKOAUTH_ERROR_USER_BLOCKED');
			return;
		}

		$response->status = Authentication::STATUS_SUCCESS;
		$response->type = 'Keycloak';
		$response->username = (string) $user->username;
		$response->email = (string) $user->email;
		$response->fullname = (string) ($user->name ?: $user->username);
	}

	private function getAdminLoginSessionService(): AdminLoginSessionService
	{
		if ($this->adminLoginSessionService === null)
		{
			$this->adminLoginSessionService = new AdminLoginSessionService();
		}

		return $this->adminLoginSessionService;
	}
}