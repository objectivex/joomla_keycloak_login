<?php

namespace Joomla\Plugin\System\Keycloakoauth\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

class DiscoverEndpointsButtonField extends FormField
{
	protected $type = 'DiscoverEndpointsButton';

	protected function getInput(): string
	{
		$token   = Factory::getApplication()->getFormToken();
		$ajaxUrl = htmlspecialchars(
			Uri::base() . 'index.php?option=com_ajax&plugin=keycloakoauth&group=system&format=json',
			ENT_QUOTES,
			'UTF-8'
		);

		$label   = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_FIELD_DISCOVER_ENDPOINTS_BUTTON'), ENT_QUOTES, 'UTF-8');
		$success = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_DISCOVER_ENDPOINTS_SUCCESS'), ENT_QUOTES, 'UTF-8');
		$error   = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_DISCOVER_ENDPOINTS_ERROR'), ENT_QUOTES, 'UTF-8');

		return <<<HTML
<button type="button" class="btn btn-secondary" id="keycloak-discover-endpoints-btn" data-ajax-url="{$ajaxUrl}" data-token="{$token}" disabled>
	{$label}
</button>
<span id="keycloak-discover-endpoints-result" class="ms-2"></span>
<script>
(function () {
	var btn              = document.getElementById('keycloak-discover-endpoints-btn');
	var result           = document.getElementById('keycloak-discover-endpoints-result');
	var baseUrlInput     = document.querySelector('[name="jform[params][base_url]"]');

	function isValidUrl(value) {
		try {
			var u = new URL(value);
			return u.protocol === 'http:' || u.protocol === 'https:';
		} catch (e) {
			return false;
		}
	}

	function updateButtonState() {
		btn.disabled = !isValidUrl(baseUrlInput ? baseUrlInput.value.trim() : '');
		if (!btn.disabled) {
			result.textContent = '';
			result.className   = 'ms-2';
		}
	}

	if (baseUrlInput) {
		baseUrlInput.addEventListener('input', updateButtonState);
		baseUrlInput.addEventListener('change', updateButtonState);
		updateButtonState();
	}

	btn.addEventListener('click', function () {
		btn.disabled       = true;
		result.textContent = '';
		result.className   = 'ms-2';

		var baseUrl = baseUrlInput.value.trim();
		var body    = new URLSearchParams();
		body.append(btn.dataset.token, '1');
		body.append('base_url', baseUrl);

		fetch(btn.dataset.ajaxUrl, {
				method:  'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body:    body.toString()
			})
			.then(function (r) {
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r.json();
			})
			.then(function (response) {
				if (!response || response.success === false) {
					throw new Error(response ? response.message : 'Unknown error');
				}
				var data = response.data[0];
				var authUrlInput     = document.querySelector('[name="jform[params][auth_url]"]');
				var tokenUrlInput    = document.querySelector('[name="jform[params][token_url]"]');
				var userinfoUrlInput = document.querySelector('[name="jform[params][userinfo_url]"]');

				if (authUrlInput)     { authUrlInput.value     = data.authorization_endpoint || ''; }
				if (tokenUrlInput)    { tokenUrlInput.value    = data.token_endpoint         || ''; }
				if (userinfoUrlInput) { userinfoUrlInput.value = data.userinfo_endpoint      || ''; }

				result.textContent = '{$success}';
				result.className   = 'ms-2 text-success';
			})
			.catch(function (e) {
				result.textContent = '{$error}' + (e.message ? ' (' + e.message + ')' : '');
				result.className   = 'ms-2 text-danger';
			})
			.finally(function () { updateButtonState(); });
	});
}());
</script>
HTML;
	}

	public function getLabel(): string
	{
		return '';
	}
}
