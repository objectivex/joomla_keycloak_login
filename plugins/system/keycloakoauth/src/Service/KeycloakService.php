<?php

namespace Joomla\Plugin\System\Keycloakoauth\Service;

use Joomla\CMS\Http\HttpFactory;

\defined('_JEXEC') or die;

final class KeycloakService
{
	/**
	 * Führt die OIDC-Discovery gegen Keycloak aus.
	 *
	 * @param string $baseUrl
	 *
	 * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string}
	 */
	public function discoverEndpoints(string $baseUrl): array
	{
		$baseUrl = trim($baseUrl);

		if (empty($baseUrl) || !filter_var($baseUrl, FILTER_VALIDATE_URL))
		{
			throw new \RuntimeException('Invalid base_url', 400);
		}

		$baseUrl      = rtrim($baseUrl, '/');
		$discoveryUrl = $baseUrl . '/.well-known/openid-configuration';

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

		return [
			'authorization_endpoint' => (string) ($body['authorization_endpoint'] ?? ''),
			'token_endpoint'         => (string) ($body['token_endpoint'] ?? ''),
			'userinfo_endpoint'      => (string) ($body['userinfo_endpoint'] ?? ''),
		];
	}

	public function getDiscoveryUrl(string $baseUrl): string
	{
		return rtrim(trim($baseUrl), '/') . '/.well-known/openid-configuration';
	}
}