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

		if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL))
		{
			throw new \RuntimeException('Invalid base_url', 400);
		}

		$parts = parse_url($baseUrl);

		if ($parts === false || !isset($parts['scheme']))
		{
			throw new \RuntimeException('Invalid base_url', 400);
		}

		$scheme = strtolower($parts['scheme']);

		if ($scheme !== 'http' && $scheme !== 'https')
		{
			throw new \RuntimeException('Invalid base_url scheme', 400);
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

		$requiredKeys = [
			'authorization_endpoint',
			'token_endpoint',
			'userinfo_endpoint',
		];

		$endpoints = [];

		foreach ($requiredKeys as $key)
		{
			if (!isset($body[$key]) || !\is_string($body[$key]) || trim($body[$key]) === '')
			{
				throw new \RuntimeException('Discovery document missing required field: ' . $key, 502);
			}

			$value = trim($body[$key]);

			if (!filter_var($value, FILTER_VALIDATE_URL))
			{
				throw new \RuntimeException('Discovery document field "' . $key . '" is not a valid URL', 502);
			}

			$endpoints[$key] = $value;
		}

		return [
			'authorization_endpoint' => $endpoints['authorization_endpoint'],
			'token_endpoint'         => $endpoints['token_endpoint'],
			'userinfo_endpoint'      => $endpoints['userinfo_endpoint'],
		];
	}

	public function getDiscoveryUrl(string $baseUrl): string
	{
		return rtrim(trim($baseUrl), '/') . '/.well-known/openid-configuration';
	}
}