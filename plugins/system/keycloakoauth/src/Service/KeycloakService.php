<?php

namespace Joomla\Plugin\System\Keycloakoauth\Service;

use Joomla\CMS\Http\HttpFactory;

\defined('_JEXEC') or die;

final class KeycloakService
{
	private const OAUTH_SCOPE = 'openid profile email';

	/**
	 * Validates that the given URL is a well-formed HTTP or HTTPS URL.
	 *
	 * @param  string  $url
	 *
	 * @return bool
	 */
	private function isValidHttpUrl(string $url): bool
	{
		$url = trim($url);

		if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL))
		{
			return false;
		}

		$scheme = parse_url($url, PHP_URL_SCHEME);

		return \in_array(\strtolower((string) $scheme), ['http', 'https'], true);
	}

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

	public function buildAuthorizationUrl(string $authorizationEndpoint, string $clientId, string $redirectUri, string $state): string
	{
		$authorizationEndpoint = trim($authorizationEndpoint);
		$clientId              = trim($clientId);
		$redirectUri           = trim($redirectUri);
		$state                 = trim($state);

		if (!$this->isValidHttpUrl($authorizationEndpoint))
		{
			throw new \RuntimeException('Invalid authorization endpoint', 400);
		}

		if ($clientId === '')
		{
			throw new \RuntimeException('Missing client_id', 400);
		}

		if (!$this->isValidHttpUrl($redirectUri))
		{
			throw new \RuntimeException('Invalid redirect_uri', 400);
		}

		if ($state === '')
		{
			throw new \RuntimeException('Missing OAuth state', 500);
		}

		$query = http_build_query([
			'response_type' => 'code',
			'client_id'     => $clientId,
			'redirect_uri'  => $redirectUri,
			'scope'         => self::OAUTH_SCOPE,
			'state'         => $state,
		], '', '&', PHP_QUERY_RFC3986);

		$separator = strpos($authorizationEndpoint, '?') === false ? '?' : '&';

		return $authorizationEndpoint . $separator . $query;
	}

	public function exchangeAuthorizationCodeForAccessToken(
		string $tokenEndpoint,
		string $clientId,
		string $clientSecret,
		string $redirectUri,
		string $authorizationCode
	): string {
		$tokenEndpoint     = trim($tokenEndpoint);
		$clientId          = trim($clientId);
		$redirectUri       = trim($redirectUri);
		$authorizationCode = trim($authorizationCode);

		if ($tokenEndpoint === '' || !filter_var($tokenEndpoint, FILTER_VALIDATE_URL))
		{
			throw new \RuntimeException('Invalid token endpoint', 400);
		}

		$scheme = parse_url($tokenEndpoint, PHP_URL_SCHEME);

		if ($scheme === null || ($scheme !== 'http' && $scheme !== 'https'))
		{
			throw new \RuntimeException('Invalid token endpoint scheme', 400);
		}

		if ($clientId === '' || $clientSecret === '' || $redirectUri === '' || $authorizationCode === '')
		{
			throw new \RuntimeException('Missing token exchange parameters', 400);
		}

		$http = HttpFactory::getHttp();
		$body = http_build_query([
			'grant_type'    => 'authorization_code',
			'code'          => $authorizationCode,
			'redirect_uri'  => $redirectUri,
			'client_id'     => $clientId,
			'client_secret' => $clientSecret,
		], '', '&', PHP_QUERY_RFC3986);

		$response = $http->post(
			$tokenEndpoint,
			$body,
			[
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			]
		);

		if ($response->code < 200 || $response->code >= 300)
		{
			throw new \RuntimeException('Token request failed with HTTP ' . $response->code, 502);
		}

		$tokenData = json_decode($response->body, true);

		if (!is_array($tokenData) || !isset($tokenData['access_token']) || !is_string($tokenData['access_token']) || trim($tokenData['access_token']) === '')
		{
			throw new \RuntimeException('Token response missing access_token', 502);
		}

		return trim($tokenData['access_token']);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function fetchUserInfo(string $userinfoEndpoint, string $accessToken): array
	{
		$userinfoEndpoint = trim($userinfoEndpoint);
		$accessToken      = trim($accessToken);

		if ($userinfoEndpoint === '' || !filter_var($userinfoEndpoint, FILTER_VALIDATE_URL))
		{
			throw new \RuntimeException('Invalid userinfo endpoint', 400);
		}

		$parts = parse_url($userinfoEndpoint);

		if ($parts === false || !isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true))
		{
			throw new \RuntimeException('Invalid userinfo endpoint', 400);
		}

		if ($accessToken === '')
		{
			throw new \RuntimeException('Missing access token', 500);
		}

		$http = HttpFactory::getHttp();
		$response = $http->get($userinfoEndpoint, [
			'Authorization' => 'Bearer ' . $accessToken,
			'Accept'        => 'application/json',
		]);

		if ($response->code < 200 || $response->code >= 300)
		{
			throw new \RuntimeException('Userinfo request failed with HTTP ' . $response->code, 502);
		}

		$userInfo = json_decode($response->body, true);

		if (!is_array($userInfo))
		{
			throw new \RuntimeException('Invalid JSON from userinfo endpoint', 502);
		}

		return $userInfo;
	}

	/**
	 * @param array<string, mixed> $userInfo
	 *
	 * @return string[]
	 */
	public function extractTopLevelFieldNames(array $userInfo): array
	{
		$fields = array_keys($userInfo);
		$fields = array_filter($fields, static fn ($field) => is_string($field) && trim($field) !== '');
		$fields = array_values(array_unique(array_map(static fn ($field) => trim($field), $fields)));

		natcasesort($fields);

		return array_values($fields);
	}
}