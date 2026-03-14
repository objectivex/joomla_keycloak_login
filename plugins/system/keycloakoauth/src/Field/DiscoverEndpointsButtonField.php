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
			Uri::root() . 'index.php?option=com_ajax&plugin=keycloakoauth&group=system&format=json&' . $token . '=1',
			ENT_QUOTES,
			'UTF-8'
		);

		$label   = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_FIELD_DISCOVER_ENDPOINTS_BUTTON'), ENT_QUOTES, 'UTF-8');
		$success = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_DISCOVER_ENDPOINTS_SUCCESS'), ENT_QUOTES, 'UTF-8');
		$error   = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_DISCOVER_ENDPOINTS_ERROR'), ENT_QUOTES, 'UTF-8');

		return <<<HTML
<button type="button" class="btn btn-secondary" id="keycloak-discover-endpoints-btn" data-ajax-url="{$ajaxUrl}">
	{$label}
</button>
<span id="keycloak-discover-endpoints-result" class="ms-2"></span>
<script>
(function () {
	var btn    = document.getElementById('keycloak-discover-endpoints-btn');
	var result = document.getElementById('keycloak-discover-endpoints-result');

	btn.addEventListener('click', function () {
		btn.disabled      = true;
		result.textContent = '';
		result.className  = 'ms-2';

		fetch(btn.dataset.ajaxUrl, {method: 'POST'})
			.then(function (r) { return r.json(); })
			.then(function () {
				result.textContent = '{$success}';
				result.className   = 'ms-2 text-success';
			})
			.catch(function () {
				result.textContent = '{$error}';
				result.className   = 'ms-2 text-danger';
			})
			.finally(function () { btn.disabled = false; });
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
