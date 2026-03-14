<?php

namespace Joomla\Plugin\System\Keycloakoauth\Extension;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;

use Joomla\CMS\Session\Session;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Plugin\System\Keycloakoauth\Service\AdminLoginSessionService;
use Joomla\Plugin\System\Keycloakoauth\Service\KeycloakService;

\defined('_JEXEC') or die;

final class Keycloakoauth extends CMSPlugin implements SubscriberInterface
{
	private ?KeycloakService $keycloakService = null;
	private ?AdminLoginSessionService $adminLoginSessionService = null;

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
			'onBeforeCompileHead'    => 'onBeforeCompileHead',
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
		$input = $this->getApplication()->getInput();
		$state = $input->getString('state', '');
		$code = $input->getString('code', '');
		$error = $input->getString('error', '');
		$hasCodeOrError = $code !== '' || $error !== '';

		if (strpos($state, 'kcadmin_') === 0 && $hasCodeOrError && !$this->getApplication()->isClient('administrator'))
		{
			Log::add(
				'KeycloakOAuth admin callback reached non-admin client: client=' . ($this->getApplication()->isClient('site') ? 'site' : 'unknown')
					. ', option=' . $input->getCmd('option', '-')
					. ', task=' . $input->getCmd('task', '-')
					. ', state=' . $state,
				Log::WARNING,
				'keycloakoauth'
			);

			$this->forwardAdminCallbackToAdministratorClient();
		}

		if ($this->isAdminCallbackRequest())
		{
			$this->handleAdminCallbackRequest();
		}

		$this->normalizeIntegrationSettings();
		Log::add('KeycloakOAuth initialized', Log::DEBUG, 'joomla');

		if ($this->isMappingCallbackRequest())
		{
			$this->handleMappingCallbackRequest();
		}
	}

	public function onBeforeCompileHead(): void
	{
		$app = $this->getApplication();

		if (!$app->isClient('administrator') || !$app->getIdentity()->guest)
		{
			return;
		}

		if (!$this->isAdminLoginAvailable() || !$this->isAdministratorLoginPage())
		{
			return;
		}

		try
		{
			$buttonUrl = $this->getKeycloakService()->buildAuthorizationUrl(
				(string) $this->params->get('auth_url', ''),
				(string) $this->params->get('client_id', ''),
				$this->getAdminRedirectUri(),
				$this->createAdminState()
			);

			Log::add('KeycloakOAuth admin login button URL generated for administrator.', Log::DEBUG, 'keycloakoauth');
		}
		catch (\Throwable $exception)
		{
			Log::add('KeycloakOAuth admin login button could not be rendered: ' . $exception->getMessage(), Log::WARNING, 'keycloakoauth');

			return;
		}

		$document = Factory::getDocument();
		$buttonLabel = Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_BUTTON');

		$document->addStyleDeclaration(
			'.keycloakoauth-admin-login{margin-top:1rem;text-align:center;}'
			. '.keycloakoauth-admin-login__button{display:inline-block;padding:0.75rem 1.25rem;border-radius:0.375rem;background:#0f5ea8;color:#fff;text-decoration:none;font-weight:600;}'
			. '.keycloakoauth-admin-login__button:hover,.keycloakoauth-admin-login__button:focus{background:#0b4b86;color:#fff;}'
		);

		$document->addScriptDeclaration(
			'(function(){'
			. 'var init=function(){'
			. 'if(document.getElementById("keycloakoauth-admin-login")){return;}'
			. 'var form=document.querySelector("form[action*=\"task=login\"], form#form-login, form[name=login]");'
			. 'if(!form){return;}'
			. 'var container=document.createElement("div");'
			. 'container.id="keycloakoauth-admin-login";'
			. 'container.className="keycloakoauth-admin-login";'
			. 'var link=document.createElement("a");'
			. 'link.className="keycloakoauth-admin-login__button";'
			. 'link.href=' . json_encode($buttonUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';'
			. 'link.textContent=' . json_encode($buttonLabel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';'
			. 'container.appendChild(link);'
			. 'form.appendChild(container);'
			. '};'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}'
			. '})();'
		);
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

		if ($task === 'admin_callback')
		{
			$app = $this->getApplication();

			if (!$app->isClient('administrator'))
			{
				Log::add('KeycloakOAuth admin callback called from non-administrator client', Log::WARNING, 'keycloakoauth');
				throw new \RuntimeException('Not authorized', 403);
			}

			$this->handleAdminCallbackRequest();

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

	private function isAdminCallbackRequest(): bool
	{
		$app = $this->getApplication();
		$input = $app->getInput();
		$state = $input->getString('state', '');
		$hasCodeOrError = $input->getString('code', '') !== '' || $input->getString('error', '') !== '';
		$isExplicitCallback = $input->getInt('keycloakoauth_admin_callback', 0) === 1;
		$isAdminLoginRoute = $input->getCmd('option', '') === ''
			|| $input->getCmd('option', '') === 'com_login'
			|| ($input->getCmd('option', '') === 'com_users' && $input->getCmd('task', '') === 'login');

		return $app->isClient('administrator')
			&& ($isExplicitCallback || $isAdminLoginRoute)
			&& $hasCodeOrError
			&& strpos($state, 'kcadmin_') === 0;
	}

	private function handleAdminCallbackRequest(): void
	{
		/** @var \Joomla\CMS\Application\CMSApplication $app */
		$app = $this->getApplication();
		$input = $app->getInput();
		$state = $input->getString('state', '');
		$error = $input->getString('error', '');
		$code = $input->getString('code', '');

		Log::add(
			'KeycloakOAuth admin callback received from provider: '
				. 'has_state=' . ($state !== '' ? '1' : '0')
				. ', state_prefix_valid=' . (strpos($state, 'kcadmin_') === 0 ? '1' : '0')
				. ', has_code=' . ($code !== '' ? '1' : '0')
				. ', has_error=' . ($error !== '' ? '1' : '0'),
			Log::WARNING,
			'keycloakoauth'
		);

		if (!$this->validateAdminState($state))
		{
			$this->renderAdminLoginError(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_INVALID_STATE'));
		}

		if ($error !== '')
		{
			$description = $input->getString('error_description', '');
			$message = Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_PROVIDER_ERROR') . ': ' . $error;

			if ($description !== '')
			{
				$message .= ' (' . $description . ')';
			}

			$this->renderAdminLoginError($message);
		}

		if ($code === '')
		{
			$this->renderAdminLoginError(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_MISSING_CODE'));
		}

		try
		{
			$service = $this->getKeycloakService();
			$redirectUri = $this->getAdminRedirectUri();
			$accessToken = $service->exchangeAuthorizationCodeForAccessToken(
				(string) $this->params->get('token_url', ''),
				(string) $this->params->get('client_id', ''),
				(string) $this->params->get('client_secret', ''),
				$redirectUri,
				$code
			);
			$userInfo = $service->fetchUserInfo((string) $this->params->get('userinfo_url', ''), $accessToken);
			$emailField = trim((string) $this->params->get('email_mapping', ''));
			$email = $service->extractStringField($userInfo, $emailField);

			if ($emailField === '')
			{
				throw new \RuntimeException(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_EMAIL_MAPPING_MISSING'));
			}

			if ($email === '')
			{
				throw new \RuntimeException(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_EMAIL_MISSING'));
			}

			$user = $this->loadUserByEmail($email);

			if ($user === null)
			{
				throw new \RuntimeException(Text::sprintf('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_USER_NOT_FOUND', $email));
			}

			$this->synchronizeMappedGroups((int) $user->id, $userInfo);
			Access::clearStatics();
			$user = $this->reloadUser((int) $user->id);

			if ((int) $user->id <= 0 || !$user->authorise('core.login.admin'))
			{
				throw new \RuntimeException(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_ACCESS_DENIED'));
			}

			$token = $this->getAdminLoginSessionService()->storePendingLogin((int) $user->id, (string) $user->username, (string) $user->email);
			$loginResult = $app->login([
				'username'            => (string) $user->username,
				'password'            => '',
				'keycloakoauth_token' => $token,
			], [
				'silent' => true,
			]);

			if ($loginResult !== true)
			{
				throw new \RuntimeException(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_AUTHENTICATION_FAILED'));
			}

			$app->redirect(Uri::base() . 'index.php');
			$app->close();
		}
		catch (\Throwable $exception)
		{
			Log::add('KeycloakOAuth admin login failed: ' . $exception->getMessage(), Log::ERROR, 'keycloakoauth');
			$this->renderAdminLoginError($exception->getMessage());
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

	private function forwardAdminCallbackToAdministratorClient(): void
	{
		$app   = $this->getApplication();
		$input = $app->getInput();

		// Only forward expected OAuth-related parameters instead of all raw $_GET values
		$allowedKeys = [
			'code',
			'state',
			'session_state',
			'iss',
		];

		$queryParams = [];

		foreach ($allowedKeys as $key)
		{
			$value = $input->getString($key, null);

			if ($value !== null && $value !== '')
			{
				$queryParams[$key] = $value;
			}
		}

		$queryParams['keycloakoauth_admin_callback'] = '1';

		$adminBase = rtrim(Uri::root(), '/') . '/administrator/index.php';
		$query     = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
		$targetUrl = $adminBase . ($query !== '' ? '?' . $query : '');

		// Avoid logging sensitive parameter values such as OAuth code/state
		$forwardedParamNames = implode(', ', array_keys($queryParams));
		Log::add(
			'KeycloakOAuth forwarding admin callback to administrator client: '
			. $adminBase
			. ' with params [' . $forwardedParamNames . ']',
			Log::WARNING,
			'keycloakoauth'
		);

		$app->redirect($targetUrl);
		$app->close();
	}

	private function handleMappingTryConnectionTask(Event $event): void
	{
		$authorizationEndpoint = (string) $this->params->get('auth_url', '');
		$tokenEndpoint         = (string) $this->params->get('token_url', '');
		$userinfoEndpoint      = (string) $this->params->get('userinfo_url', '');
		$clientId              = (string) $this->params->get('client_id', '');
		$clientSecret          = (string) $this->params->get('client_secret', '');
		$redirectUri           = $this->getEffectiveRedirectUri();
		$redirectSource        = $this->getRedirectUriSource();

		if (
			$authorizationEndpoint === '' ||
			$tokenEndpoint === '' ||
			$userinfoEndpoint === '' ||
			$clientId === '' ||
			$clientSecret === ''
		)
		{
			throw new \RuntimeException(
				'Missing OAuth configuration. Please configure Authorization URL, Token URL, Userinfo URL, Client ID, and Client Secret.',
				400
			);
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

	private function isAdministratorLoginPage(): bool
	{
		$input = $this->getApplication()->getInput();
		$option = $input->getCmd('option', '');
		$task = $input->getCmd('task', '');

		return $option === ''
			|| $option === 'com_login'
			|| ($option === 'com_users' && $task === 'login');
	}

	private function isAdminLoginAvailable(): bool
	{
		return $this->normalizeBooleanParamValue($this->params->get('allow_admin_user_login', 0)) === 1
			&& trim((string) $this->params->get('client_id', '')) !== ''
			&& trim((string) $this->params->get('client_secret', '')) !== ''
			&& trim((string) $this->params->get('auth_url', '')) !== ''
			&& trim((string) $this->params->get('token_url', '')) !== ''
			&& trim((string) $this->params->get('userinfo_url', '')) !== ''
			&& trim((string) $this->params->get('email_mapping', '')) !== '';
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

	private function getAdminRedirectUri(): string
	{
		$configuredRedirectUri = trim((string) $this->params->get('redirect_uri', ''));

		if ($configuredRedirectUri !== '')
		{
			$configuredUri = Uri::getInstance($configuredRedirectUri);

			if ($configuredUri->getVar('keycloakoauth_admin_callback', '0') === '1')
			{
				return $configuredRedirectUri;
			}
		}

		$adminRoot = rtrim(Uri::root(), '/') . '/administrator/index.php';
		$callbackUri = Uri::getInstance($adminRoot);
		$callbackUri->setVar('option', 'com_ajax');
		$callbackUri->setVar('plugin', 'keycloakoauth');
		$callbackUri->setVar('group', 'system');
		$callbackUri->setVar('format', 'raw');
		$callbackUri->setVar('task', 'admin_callback');
		$callbackUri->setVar('keycloakoauth_admin_callback', '1');

		return $callbackUri->toString(['scheme', 'host', 'port', 'path', 'query']);
	}

	private function getAdminLoginPageUrl(): string
	{
		return Uri::base() . 'index.php';
	}

	private function getRedirectUriSource(): string
	{
		$configuredRedirectUri = trim((string) $this->params->get('redirect_uri', ''));

		return $configuredRedirectUri !== '' ? 'configured' : 'fallback';
	}

	private function createMappingState(): string
	{
		return $this->getKeycloakService()->buildSignedState('kcmap_', $this->getStateSecret());
	}

	private function validateMappingState(string $state): bool
	{
		return $this->getKeycloakService()->validateSignedState($state, 'kcmap_', $this->getStateSecret());
	}

	private function createAdminState(): string
	{
		return $this->getKeycloakService()->buildSignedState('kcadmin_', $this->getStateSecret());
	}

	private function validateAdminState(string $state): bool
	{
		return $this->getKeycloakService()->validateSignedState($state, 'kcadmin_', $this->getStateSecret());
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

	private function getAdminLoginSessionService(): AdminLoginSessionService
	{
		if ($this->adminLoginSessionService === null)
		{
			$this->adminLoginSessionService = new AdminLoginSessionService();
		}

		return $this->adminLoginSessionService;
	}

	private function renderAdminLoginError(string $message): void
	{
		$title = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_TITLE'), ENT_QUOTES, 'UTF-8');
		$safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
		$backLabel = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_BACK'), ENT_QUOTES, 'UTF-8');
		$backUrl = htmlspecialchars($this->getAdminLoginPageUrl(), ENT_QUOTES, 'UTF-8');

		$content = '<!doctype html>'
			. '<html><head><meta charset="utf-8"><title>' . $title . '</title>'
			. '<style>body{font-family:sans-serif;padding:24px;max-width:640px;margin:0 auto;}p{margin-bottom:16px;color:#b40000;}a{color:#0f5ea8;}</style>'
			. '</head><body>'
			. '<h2>' . $title . '</h2>'
			. '<p>' . $safeMessage . '</p>'
			. '<p><a href="' . $backUrl . '">' . $backLabel . '</a></p>'
			. '</body></html>';

		$this->renderPopupResponse($content);
	}

	private function loadUserByEmail(string $email): ?object
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName(['id']))
			->from($db->quoteName('#__users'))
			->where($db->quoteName('email') . ' = :email')
			->bind(':email', $email)
			->setLimit(2);

		$db->setQuery($query);
		$userIds = $db->loadColumn();

		if (!is_array($userIds) || $userIds === [])
		{
			return null;
		}

		if (count($userIds) > 1)
		{
			throw new \RuntimeException(Text::sprintf('PLG_SYSTEM_KEYCLOAKOAUTH_ADMIN_LOGIN_DUPLICATE_EMAIL', $email));
		}

		return $this->reloadUser((int) $userIds[0]);
	}

	private function reloadUser(int $userId): object
	{
		return Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);
	}

	private function synchronizeMappedGroups(int $userId, array $userInfo): void
	{
		$groupMappings = $this->getConfiguredGroupMappings();

		if ($groupMappings === [])
		{
			return;
		}

		$roles = $this->getKeycloakService()->extractClientRoles($userInfo, (string) $this->params->get('client_id', ''));
		$user = $this->reloadUser($userId);
		$currentGroups = array_map('intval', array_values((array) ($user->groups ?? [])));
		$targetGroupIds = [];

		foreach ($groupMappings as $mapping)
		{
			if (in_array($mapping['claim_value'], $roles, true))
			{
				$targetGroupIds[] = (int) $mapping['group_id'];
			}
		}

		$targetGroupIds = array_values(array_unique($targetGroupIds));
		$groupsToAdd = array_values(array_diff($targetGroupIds, $currentGroups));
		$groupsToRemove = array_values(array_diff($currentGroups, $targetGroupIds));

		Log::add(
			'KeycloakOAuth group matching: user_id=' . $userId
				. ', roles=' . json_encode($roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
				. ', current_groups=' . json_encode($currentGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
				. ', target_groups=' . json_encode($targetGroupIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
				. ', add=' . json_encode($groupsToAdd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
				. ', remove=' . json_encode($groupsToRemove, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			Log::DEBUG,
			'keycloakoauth'
		);

		foreach ($groupsToAdd as $groupId)
		{
			Log::add('KeycloakOAuth adding user to group: user_id=' . $userId . ', group_id=' . $groupId, Log::DEBUG, 'keycloakoauth');
			$this->addUserToGroupDirect($userId, (int) $groupId);
		}

		foreach ($groupsToRemove as $groupId)
		{
			Log::add('KeycloakOAuth removing user from group: user_id=' . $userId . ', group_id=' . $groupId, Log::DEBUG, 'keycloakoauth');
			$this->removeUserFromGroupDirect($userId, (int) $groupId);
		}
	}

	private function addUserToGroupDirect(int $userId, int $groupId): void
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		$check = $db->getQuery(true)
			->select('1')
			->from($db->quoteName('#__user_usergroup_map'))
			->where($db->quoteName('user_id') . ' = ' . $userId)
			->where($db->quoteName('group_id') . ' = ' . $groupId);
		$db->setQuery($check);

		if ($db->loadResult() !== null)
		{
			return;
		}

		$insert = $db->getQuery(true)
			->insert($db->quoteName('#__user_usergroup_map'))
			->columns([$db->quoteName('user_id'), $db->quoteName('group_id')])
			->values($userId . ', ' . $groupId);
		$db->setQuery($insert);
		$db->execute();
	}

	private function removeUserFromGroupDirect(int $userId, int $groupId): void
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		$delete = $db->getQuery(true)
			->delete($db->quoteName('#__user_usergroup_map'))
			->where($db->quoteName('user_id') . ' = ' . $userId)
			->where($db->quoteName('group_id') . ' = ' . $groupId);
		$db->setQuery($delete);
		$db->execute();
	}

	/**
	 * @return array<int, array{group_id:int, claim_value:string}>
	 */
	private function getConfiguredGroupMappings(): array
	{
		$rawMappings = $this->params->get('group_mappings', '[]');
		$decoded = is_string($rawMappings) ? json_decode($rawMappings, true) : $rawMappings;

		if (!is_array($decoded))
		{
			return [];
		}

		$mappings = [];

		foreach ($decoded as $mapping)
		{
			if (!is_array($mapping))
			{
				continue;
			}

			$groupId = (int) ($mapping['group_id'] ?? 0);
			$claimValue = trim((string) ($mapping['claim_value'] ?? ''));

			if ($groupId <= 0 || $claimValue === '')
			{
				continue;
			}

			$mappings[] = [
				'group_id' => $groupId,
				'claim_value' => $claimValue,
			];
		}

		return $mappings;
	}
}
