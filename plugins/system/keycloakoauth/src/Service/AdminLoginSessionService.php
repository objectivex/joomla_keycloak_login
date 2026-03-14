<?php

namespace Joomla\Plugin\System\Keycloakoauth\Service;

use Joomla\CMS\Factory;
use Joomla\Session\SessionInterface;

\defined('_JEXEC') or die;

final class AdminLoginSessionService
{
	private const SESSION_KEY = 'keycloakoauth.admin_login';

	public function storePendingLogin(int $userId, string $username, string $email): string
	{
		$token = bin2hex(random_bytes(32));
		$session = Factory::getContainer()->get(SessionInterface::class);

		$session->set(self::SESSION_KEY, [
			'token' => $token,
			'user_id' => $userId,
			'username' => $username,
			'email' => $email,
			'issued_at' => time(),
		]);

		return $token;
	}

	/**
	 * @return array{token:string,user_id:int,username:string,email:string}|null
	 */
	public function consumePendingLogin(string $token): ?array
	{
		$token = trim($token);
		$session = Factory::getContainer()->get(SessionInterface::class);
		$payload = $session->get(self::SESSION_KEY);
		$session->remove(self::SESSION_KEY);

		if (!is_array($payload) || $token === '')
		{
			return null;
		}

		$storedToken = (string) ($payload['token'] ?? '');
		$issuedAt = isset($payload['issued_at']) ? (int) $payload['issued_at'] : 0;
		$maxTokenAgeSeconds = 300;

		if ($storedToken === '' || !hash_equals($storedToken, $token) || $issuedAt <= 0 || (time() - $issuedAt) > $maxTokenAgeSeconds)
		{
			return null;
		}

		$userId = (int) ($payload['user_id'] ?? 0);
		$username = trim((string) ($payload['username'] ?? ''));
		$email = trim((string) ($payload['email'] ?? ''));

		if ($userId <= 0 || $username === '')
		{
			return null;
		}

		return [
			'token' => $storedToken,
			'user_id' => $userId,
			'username' => $username,
			'email' => $email,
		];
	}
}