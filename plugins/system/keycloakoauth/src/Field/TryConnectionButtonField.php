<?php

namespace Joomla\Plugin\System\Keycloakoauth\Field;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

class TryConnectionButtonField extends FormField
{
	protected $type = 'TryConnectionButton';

	protected function getInput(): string
	{
		$id      = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
		$token   = Session::getFormToken();
		$ajaxUrl = htmlspecialchars(
			Uri::base() . 'index.php?option=com_ajax&plugin=keycloakoauth&group=system&format=json',
			ENT_QUOTES,
			'UTF-8'
		);

		$label        = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_FIELD_TRY_CONNECTION_BUTTON'), ENT_QUOTES, 'UTF-8');
		$success      = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_TRY_SUCCESS'), ENT_QUOTES, 'UTF-8');
		$error        = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_TRY_ERROR'), ENT_QUOTES, 'UTF-8');
		$popupBlocked = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_BLOCKED'), ENT_QUOTES, 'UTF-8');
		$popupTitle   = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_TITLE'), ENT_QUOTES, 'UTF-8');
		$popupLoading = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_POPUP_CONNECTING'), ENT_QUOTES, 'UTF-8');
		$noSelection  = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_NO_SELECTION'), ENT_QUOTES, 'UTF-8');

		return <<<HTML
<button type="button" class="btn btn-secondary" id="{$id}" data-ajax-url="{$ajaxUrl}" data-token="{$token}">
	{$label}
</button>
<span id="{$id}-result" class="ms-2"></span>
<script>
(function () {
	var btn = document.getElementById('{$id}');
	var result = document.getElementById('{$id}-result');

	if (!btn) {
		return;
	}

	function showStatus(message, cssClass) {
		result.textContent = message;
		result.className = 'ms-2 ' + cssClass;
	}

	function getSelect(selector) {
		return document.querySelector(selector);
	}

	function fillSelect(select, fields) {
		if (!select) {
			return;
		}

		var current = (select.value || '').trim();
		var normalized = [];

		for (var i = 0; i < fields.length; i++) {
			if (typeof fields[i] === 'string' && fields[i].trim() !== '') {
				normalized.push(fields[i].trim());
			}
		}

		normalized = Array.from(new Set(normalized)).sort(function (a, b) {
			return a.localeCompare(b, undefined, {sensitivity: 'base'});
		});

		select.innerHTML = '';

		var emptyOption = document.createElement('option');
		emptyOption.value = '';
		emptyOption.textContent = '{$noSelection}';
		select.appendChild(emptyOption);

		for (var j = 0; j < normalized.length; j++) {
			var opt = document.createElement('option');
			opt.value = normalized[j];
			opt.textContent = normalized[j];
			select.appendChild(opt);
		}

		if (current !== '') {
			var exists = normalized.indexOf(current) !== -1;

			if (!exists) {
				var legacy = document.createElement('option');
				legacy.value = current;
				legacy.textContent = current;
				select.appendChild(legacy);
			}

			select.value = current;
		}
	}

	function handleMappingMessage(event) {
		if (event.origin !== window.location.origin) {
			return;
		}

		if (!event.data || event.data.type !== 'keycloakoauth:mapping-fields' || !Array.isArray(event.data.fields)) {
			return;
		}

		var usernameSelect = getSelect('[name="jform[params][username_mapping]"]');
		var emailSelect = getSelect('[name="jform[params][email_mapping]"]');

		fillSelect(usernameSelect, event.data.fields);
		fillSelect(emailSelect, event.data.fields);
		showStatus('{$success}', 'text-success');
	}

	window.addEventListener('message', handleMappingMessage);

	btn.addEventListener('click', function () {
		btn.disabled = true;
		showStatus('', '');

		var popup = window.open('', 'keycloakoauth_mapping_popup', 'width=900,height=700,resizable=yes,scrollbars=yes');

		if (!popup) {
			showStatus('{$popupBlocked}', 'text-danger');
			btn.disabled = false;
			return;
		}

		popup.document.write('<!doctype html><html><head><title>{$popupTitle}</title></head><body><p>{$popupLoading}</p></body></html>');
		popup.document.close();

		var body = new URLSearchParams();
		body.append(btn.dataset.token, '1');
		body.append('task', 'mapping_try_connection');

		fetch(btn.dataset.ajaxUrl, {
			method: 'POST',
			headers: {'Content-Type': 'application/x-www-form-urlencoded'},
			body: body.toString()
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}

				return response.json();
			})
			.then(function (response) {
				if (!response || response.success === false) {
					throw new Error(response && response.message ? response.message : 'Unknown error');
				}

				if (!response.data || !Array.isArray(response.data) || !response.data[0] || !response.data[0].authorization_url) {
					throw new Error('Missing authorization URL');
				}

				popup.location.href = response.data[0].authorization_url;
			})
			.catch(function (e) {
				showStatus('{$error}' + (e.message ? ' (' + e.message + ')' : ''), 'text-danger');

				if (!popup.closed) {
					popup.document.open();
					popup.document.write('<!doctype html><html><head><title>{$popupTitle}</title></head><body><p>{$error}</p><p id="kcErrorMessage"></p></body></html>');
					popup.document.close();

					try {
						var errorElement = popup.document.getElementById('kcErrorMessage');
						if (errorElement) {
							errorElement.textContent = e.message || '';
						}
					} catch (ignore) {}
				}
			})
			.finally(function () {
				btn.disabled = false;
			});
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