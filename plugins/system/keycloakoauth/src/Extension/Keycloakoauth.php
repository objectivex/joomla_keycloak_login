<?php

namespace Joomla\Plugin\System\Keycloakoauth\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
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
		$this->normalizeIntegrationSettings();
		Log::add('KeycloakOAuth initialized', Log::DEBUG, 'joomla');

		if ($this->isMappingCallbackRequest())
		{
			$this->handleMappingCallbackRequest();
		}
	}

	/**
	 * AJAX-Handler: führt die OpenID-Connect-Discovery server-seitig aus
	 *
	 * @return array
	 * @since  1.0.0
	 */
	public function onAjaxKeycloakoauth(Event $event): void
	{
		$input = $this->getApplication()->getInput();
		$task  = $input->getCmd('task', $input->post->getCmd('task', 'discover'));

		Log::add('KeycloakOAuth AJAX called for task: ' . $task, Log::DEBUG, 'keycloakoauth');

		if ($task === 'mapping_callback')
		{
			$this->handleMappingCallbackRequest();

			return;
		}
	
 		if ($input->getMethod() !== 'POST')
 		{
			Log::add('KeycloakOAuth AJAX called with invalid HTTP method', Log::WARNING, 'keycloakoauth');
 			throw new \RuntimeException('Method Not Allowed', 405);
 		}


		if (!Session::checkToken('post'))
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

		switch ($task)
		{
			case 'discover':
				$this->handleDiscoveryTask($event, $app->getInput()->post->getString('base_url', ''));
				break;

			case 'mapping_try_connection':
				$this->handleMappingTryConnectionTask($event);
				break;

			default:
				throw new \RuntimeException('Unknown task', 400);
		}
	}

	private function handleDiscoveryTask(Event $event, string $baseUrl): void
	{
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

	private function handleMappingTryConnectionTask(Event $event): void
	{
		$authorizationEndpoint = (string) $this->params->get('auth_url', '');
		$clientId              = (string) $this->params->get('client_id', '');
		$redirectUri           = $this->getEffectiveRedirectUri();
		$redirectSource        = $this->getRedirectUriSource();

		if ($authorizationEndpoint === '' || $clientId === '')
		{
			throw new \RuntimeException('Missing OAuth configuration. Please configure Authorization URL and Client ID.', 400);
		}

		$state = $this->createMappingState();

		$authorizationUrl = $this->getKeycloakService()->buildAuthorizationUrl(
			$authorizationEndpoint,
			$clientId,
			$redirectUri,
			$state
		);

		Log::add('KeycloakOAuth mapping redirect URI (' . $redirectSource . '): ' . $redirectUri, Log::DEBUG, 'keycloakoauth');

		$results   = (array) $event->getArgument('result', []);
		$results[] = ['authorization_url' => $authorizationUrl];
		$event->setArgument('result', $results);

		Log::add('KeycloakOAuth mapping connection prepared', Log::DEBUG, 'keycloakoauth');
	}

	private function isMappingCallbackRequest(): bool
	{
		$input = $this->getApplication()->getInput();
		$state = $input->getString('state', '');
		$hasCodeOrError = $input->getString('code', '') !== '' || $input->getString('error', '') !== '';

		// Require an OAuth code or error parameter
		if (!$hasCodeOrError)
		{
			return false;
		}

		// Require a mapping state prefix to distinguish mapping callbacks
		if ($state === '' || strpos($state, 'kcmap_') !== 0)
		{
			return false;
		}

		// Further constrain detection so we only treat requests as mapping callbacks
		// when they are sent to our expected endpoint or include the explicit marker
		// added to the redirect URI.
		$isAjaxRoute = $input->getCmd('option') === 'com_ajax'
			&& $input->getCmd('plugin') === 'keycloakoauth'
			&& $input->getCmd('task') === 'mapping_callback';

		$hasCallbackMarker = $input->getInt('keycloakoauth_mapping_callback', 0) === 1;

		if (!$isAjaxRoute && !$hasCallbackMarker)
		{
			return false;
		}

		return true;
	}

	private function handleMappingCallbackRequest(): void
	{
		$app = $this->getApplication();
		$input = $app->getInput();

		$state = $input->getString('state', '');

		Log::add('KeycloakOAuth mapping callback received with state: ' . $state, Log::DEBUG, 'keycloakoauth');
		if (!$this->validateMappingState($state))
		{
			$this->renderMappingPopupError('Invalid OAuth state. Please retry the connection test.');
		}

		$error = $input->getString('error', '');

		if ($error !== '')
		{
			$description = $input->getString('error_description', '');
			$message = Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_LOGIN_FAILED') . ': ' . $error;

			if ($description !== '')
			{
				$message .= ' (' . $description . ')';
			}

			$this->renderMappingPopupError($message);
		}

		$code = $input->getString('code', '');

		if ($code === '')
		{
			$this->renderMappingPopupError('Missing authorization code in callback response.');
		}

		$tokenEndpoint = (string) $this->params->get('token_url', '');
		$userinfoEndpoint = (string) $this->params->get('userinfo_url', '');
		$clientId = (string) $this->params->get('client_id', '');
		$clientSecret = (string) $this->params->get('client_secret', '');
		$redirectUri = $this->getEffectiveRedirectUri();
		$redirectSource = $this->getRedirectUriSource();
		Log::add('KeycloakOAuth callback uses redirect URI (' . $redirectSource . '): ' . $redirectUri, Log::DEBUG, 'keycloakoauth');

		try
		{
			$service = $this->getKeycloakService();
			$accessToken = $service->exchangeAuthorizationCodeForAccessToken(
				$tokenEndpoint,
				$clientId,
				$clientSecret,
				$redirectUri,
				$code
			);
			$userInfo = $service->fetchUserInfo($userinfoEndpoint, $accessToken);
			$fields = $service->extractTopLevelFieldNames($userInfo);

			$this->renderMappingPopupSuccess($userInfo, $fields);
		}
		catch (\Throwable $exception)
		{
			Log::add('KeycloakOAuth mapping callback failed: ' . $exception->getMessage(), Log::ERROR, 'keycloakoauth');
			$this->renderMappingPopupError($exception->getMessage());
		}
	}

	/**
	 * @param array<string, mixed> $userInfo
	 * @param string[]             $fields
	 */
	private function renderMappingPopupSuccess(array $userInfo, array $fields): void
	{
		$title = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_TITLE'), ENT_QUOTES, 'UTF-8');
		$heading = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_FIELDS_HEADING'), ENT_QUOTES, 'UTF-8');
		$buttonText = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_USE_FIELDS'), ENT_QUOTES, 'UTF-8');
		$noFields = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_NO_FIELDS'), ENT_QUOTES, 'UTF-8');
		$tableHeaderField = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_TABLE_FIELD'), ENT_QUOTES, 'UTF-8');
		$tableHeaderValue = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_TABLE_VALUE'), ENT_QUOTES, 'UTF-8');

		$rowsHtml = '';

		foreach ($userInfo as $key => $value)
		{
			if (!is_string($key) || trim($key) === '')
			{
				continue;
			}

			$safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');

			if (is_array($value) || is_object($value))
			{
				$jsonValue = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

				if ($jsonValue === false)
				{
					$jsonValue = 'null';
				}

				$safeValue = '<pre class="value-json">' . htmlspecialchars($jsonValue, ENT_QUOTES, 'UTF-8') . '</pre>';
			}
			elseif (is_bool($value))
			{
				$safeValue = htmlspecialchars(
					$value
						? Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_BOOLEAN_TRUE')
						: Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_BOOLEAN_FALSE'),
					ENT_QUOTES,
					'UTF-8'
				);
			}
			elseif ($value === null)
			{
				$safeValue = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_NULL'), ENT_QUOTES, 'UTF-8');
			}
			else
			{
				$safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
			}

			$rowsHtml .= '<tr><th scope="row">' . $safeKey . '</th><td>' . $safeValue . '</td></tr>';
		}

		if ($rowsHtml === '')
		{
			$rowsHtml = '<tr><td colspan="2">' . htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_NO_DATA'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
		}

		$fieldsJson = json_encode(array_values($fields), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if ($fieldsJson === false)
		{
			$fieldsJson = '[]';
		}

		$originUri = Uri::getInstance(Uri::root());
		$safeOrigin = htmlspecialchars($originUri->toString(['scheme', 'host', 'port']), ENT_QUOTES, 'UTF-8');

		$content = '<!doctype html>'
			. '<html><head><meta charset="utf-8"><title>' . $title . '</title>'
			. '<style>body{font-family:sans-serif;padding:16px;}table{width:100%;border-collapse:collapse;margin-bottom:16px;}th,td{padding:8px 10px;border:1px solid #ddd;vertical-align:top;text-align:left;}thead th{background:#f5f5f5;}pre.value-json{margin:0;padding:10px;background:#f5f5f5;border:1px solid #ddd;max-height:260px;overflow:auto;}button{padding:8px 14px;}</style>'
			. '</head><body>'
			. '<h3>' . $heading . '</h3>'
			. '<table><thead><tr><th>' . $tableHeaderField . '</th><th>' . $tableHeaderValue . '</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
			. '<p id="no-fields" style="display:none;color:#b40000;">' . $noFields . '</p>'
			. '<button id="use-fields" type="button">' . $buttonText . '</button>'
			. '<script>'
			. '(function(){'
			. 'var fields=' . $fieldsJson . ';'
			. 'var btn=document.getElementById("use-fields");'
			. 'var noFields=document.getElementById("no-fields");'
			. 'if(!Array.isArray(fields)||fields.length===0){noFields.style.display="block";}'
			. 'btn.addEventListener("click",function(){'
			. 'if(window.opener&&!window.opener.closed){window.opener.postMessage({type:"keycloakoauth:mapping-fields",fields:fields},"' . $safeOrigin . '");}'
			. 'window.close();'
			. '});'
			. '})();'
			. '</script>'
			. '</body></html>';

		$this->renderPopupResponse($content);
	}

	private function renderMappingPopupError(string $message): void
	{
		$title = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_TITLE'), ENT_QUOTES, 'UTF-8');
		$safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

		$content = '<!doctype html>'
			. '<html><head><meta charset="utf-8"><title>' . $title . '</title>'
			. '<style>body{font-family:sans-serif;padding:16px;}p{color:#b40000;}</style>'
			. '</head><body>'
			. '<h3>' . $title . '</h3>'
			. '<p>' . $safeMessage . '</p>'
			. '</body></html>';

		$this->renderPopupResponse($content);
	}

	private function renderPopupResponse(string $content): void
	{
		$app = $this->getApplication();
		@header('Content-Type: text/html; charset=utf-8');
		echo $content;
		$app->close();
	}

	private function getEffectiveRedirectUri(): string
	{
		$configuredRedirectUri = trim((string) $this->params->get('redirect_uri', ''));
		$siteRoot = Uri::getInstance(Uri::root());

		if ($configuredRedirectUri !== '')
		{
			$configuredUri = Uri::getInstance($configuredRedirectUri);
			$siteOrigin = $siteRoot->toString(['scheme', 'host', 'port']);
			$configuredOrigin = $configuredUri->toString(['scheme', 'host', 'port']);
			$configuredPath = rtrim((string) $configuredUri->getPath(), '/');

			$hasCallbackTask = (string) $configuredUri->getVar('option', '') === 'com_ajax'
				&& (string) $configuredUri->getVar('plugin', '') === 'keycloakoauth'
				&& (string) $configuredUri->getVar('task', '') === 'mapping_callback';

			if ($hasCallbackTask)
			{
				return $configuredRedirectUri;
			}

			// If configured redirect points to homepage on this Joomla instance, force callback endpoint.
			if ($configuredOrigin === $siteOrigin && ($configuredPath === '' || $configuredPath === '/index.php'))
			{
				$callbackUri = Uri::getInstance($configuredOrigin . '/index.php');
				$callbackUri->setVar('option', 'com_ajax');
				$callbackUri->setVar('plugin', 'keycloakoauth');
				$callbackUri->setVar('group', 'system');
				$callbackUri->setVar('format', 'raw');
				$callbackUri->setVar('task', 'mapping_callback');
				$callbackUri->setVar('keycloakoauth_mapping_callback', '1');

				return $callbackUri->toString(['scheme', 'host', 'port', 'path', 'query']);
			}

			return $configuredRedirectUri;
		}

		// In administrator context Uri::base() points to /administrator; use a dedicated site callback endpoint.
		$path = (string) $siteRoot->getPath();
		$normalizedPath = rtrim($path, '/');

		if ($normalizedPath === '/administrator')
		{
			$siteRoot->setPath('/');
		}

		$basePath = rtrim((string) $siteRoot->getPath(), '/');

		if ($basePath === '')
		{
			$basePath = '/';
		}

		$callbackPath = $basePath === '/' ? '/index.php' : $basePath . '/index.php';
		$callbackUri = Uri::getInstance($siteRoot->toString(['scheme', 'host', 'port']) . $callbackPath);
		$callbackUri->setVar('option', 'com_ajax');
		$callbackUri->setVar('plugin', 'keycloakoauth');
		$callbackUri->setVar('group', 'system');
		$callbackUri->setVar('format', 'raw');
		$callbackUri->setVar('task', 'mapping_callback');
		$callbackUri->setVar('keycloakoauth_mapping_callback', '1');

		return $callbackUri->toString(['scheme', 'host', 'port', 'path', 'query']);
	}

	private function getRedirectUriSource(): string
	{
		$configuredRedirectUri = trim((string) $this->params->get('redirect_uri', ''));

		return $configuredRedirectUri !== '' ? 'configured' : 'fallback';
	}

	private function createMappingState(): string
	{
		$issuedAt = time();
		$nonce = bin2hex(random_bytes(16));
		$payload = $issuedAt . '.' . $nonce;
		$signature = hash_hmac('sha256', $payload, $this->getStateSecret());

		return 'kcmap_' . $payload . '.' . $signature;
	}

	private function validateMappingState(string $state): bool
	{
		$state = trim($state);

		if ($state === '' || strpos($state, 'kcmap_') !== 0)
		{
			return false;
		}

		$raw = substr($state, 6);
		$parts = explode('.', $raw);

		if (count($parts) !== 3)
		{
			return false;
		}

		[$issuedAtRaw, $nonce, $signature] = $parts;

		if (!ctype_digit($issuedAtRaw) || strlen($nonce) !== 32 || !ctype_xdigit($nonce) || strlen($signature) !== 64 || !ctype_xdigit($signature))
		{
			return false;
		}

		$issuedAt = (int) $issuedAtRaw;

		// Keep state validity short to limit replay window.
		if ($issuedAt <= 0 || (time() - $issuedAt) > 600)
		{
			return false;
		}

		$payload = $issuedAtRaw . '.' . strtolower($nonce);
		$expectedSignature = hash_hmac('sha256', $payload, $this->getStateSecret());

		return hash_equals($expectedSignature, strtolower($signature));
	}

	private function getStateSecret(): string
	{
		$secret = (string) Factory::getConfig()->get('secret', '');

		if ($secret === '')
		{
			$secret = __CLASS__;
		}

		return $secret;
	}

	private function normalizeIntegrationSettings(): void
	{
		$this->params->set('allow_normal_user_login', $this->normalizeBooleanParamValue($this->params->get('allow_normal_user_login', 0)));
		$this->params->set('allow_admin_user_login', $this->normalizeBooleanParamValue($this->params->get('allow_admin_user_login', 0)));
		$this->params->set('allow_new_user_creation_on_logon', $this->normalizeBooleanParamValue($this->params->get('allow_new_user_creation_on_logon', 0)));
	}

	/**
	 * @param mixed $value
	 */
	private function normalizeBooleanParamValue($value): int
	{
		$value = is_string($value) ? strtolower(trim($value)) : $value;

		if ($value === 1 || $value === '1' || $value === true || $value === 'true' || $value === 'on' || $value === 'yes')
		{
			return 1;
		}

		return 0;
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
