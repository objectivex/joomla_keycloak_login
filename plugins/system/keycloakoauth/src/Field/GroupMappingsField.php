<?php

namespace Joomla\Plugin\System\Keycloakoauth\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

class GroupMappingsField extends FormField
{
	protected $type = 'GroupMappings';

	protected function getInput(): string
	{
		$id = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
		$name = htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8');
		$savedValue = is_string($this->value) ? $this->value : '';

		$groups = $this->getGroups();
		$initialMappings = $this->parseMappings($savedValue);

		$groupsJson = json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$mappingsJson = json_encode($initialMappings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

		if ($groupsJson === false)
		{
			$groupsJson = '[]';
		}

		if ($mappingsJson === false)
		{
			$mappingsJson = '[]';
		}

		$addLabel = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_GROUP_MAPPING_ADD'), ENT_QUOTES, 'UTF-8');
		$groupLabel = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_GROUP_MAPPING_GROUP'), ENT_QUOTES, 'UTF-8');
		$claimLabel = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_GROUP_MAPPING_CLAIM_VALUE'), ENT_QUOTES, 'UTF-8');
		$removeLabel = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_GROUP_MAPPING_REMOVE'), ENT_QUOTES, 'UTF-8');
		$selectGroupLabel = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_GROUP_MAPPING_SELECT_GROUP'), ENT_QUOTES, 'UTF-8');
		$unknownGroupPattern = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_GROUP_MAPPING_UNKNOWN_GROUP'), ENT_QUOTES, 'UTF-8');

		return <<<HTML
<div id="{$id}_container" class="keycloak-group-mappings">
	<div class="row fw-semibold mb-2">
		<div class="col-md-4">{$groupLabel}</div>
		<div class="col-md-6">{$claimLabel}</div>
		<div class="col-md-2"></div>
	</div>
	<div id="{$id}_rows"></div>
	<button type="button" class="btn btn-secondary" id="{$id}_add">{$addLabel}</button>
	<input type="hidden" id="{$id}_value" name="{$name}" value="" />
</div>
<script>
(function () {
	var container = document.getElementById('{$id}_container');
	var rowsContainer = document.getElementById('{$id}_rows');
	var addButton = document.getElementById('{$id}_add');
	var hiddenInput = document.getElementById('{$id}_value');
	var groups = {$groupsJson};
	var rows = {$mappingsJson};
	var nextId = 0;

	if (!container || !rowsContainer || !addButton || !hiddenInput) {
		return;
	}

	function normalizeRows() {
		var cleaned = [];
		var maxSeenId = 0;

		for (var i = 0; i < rows.length; i++) {
			var item = rows[i] || {};
			var groupId = String(item.group_id || '').trim();
			var claimValue = String(item.claim_value || '').trim();
			var uid = String(item.uid || '').trim();

			if (uid !== '') {
				var match = uid.match(/^row_(\d+)$/);

				if (match) {
					var parsed = parseInt(match[1], 10);

					if (!isNaN(parsed) && parsed > maxSeenId) {
						maxSeenId = parsed;
					}
				}
			}

			cleaned.push({
				uid: uid || ('row_' + (++nextId)),
				group_id: groupId,
				claim_value: claimValue
			});
		}

		nextId = Math.max(nextId, maxSeenId);
		rows = cleaned;
	}

	function selectedGroupIds(excludeUid) {
		var selected = [];

		for (var i = 0; i < rows.length; i++) {
			if (excludeUid && rows[i].uid === excludeUid) {
				continue;
			}

			if (rows[i].group_id) {
				selected.push(String(rows[i].group_id));
			}
		}

		return selected;
	}

	function findGroupLabel(groupId) {
		for (var i = 0; i < groups.length; i++) {
			if (String(groups[i].id) === String(groupId)) {
				return String(groups[i].title);
			}
		}

		return null;
	}

	function unknownGroupLabel(groupId) {
		return '{$unknownGroupPattern}'.replace('%d', String(groupId));
	}

	function buildSelectOptions(currentUid, currentGroupId) {
		var html = '<option value="">{$selectGroupLabel}</option>';
		var selected = selectedGroupIds(currentUid);

		for (var i = 0; i < groups.length; i++) {
			var id = String(groups[i].id);

			if (selected.indexOf(id) !== -1 && id !== String(currentGroupId || '')) {
				continue;
			}

			var isSelected = id === String(currentGroupId || '') ? ' selected="selected"' : '';
			html += '<option value="' + id.replace(/"/g, '&quot;') + '"' + isSelected + '>' + String(groups[i].title)
				.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') + '</option>';
		}

		if (currentGroupId) {
			var knownLabel = findGroupLabel(currentGroupId);

			if (!knownLabel) {
				html += '<option value="' + String(currentGroupId).replace(/"/g, '&quot;') + '" selected="selected">'
					+ unknownGroupLabel(currentGroupId).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
					+ '</option>';
			}
		}

		return html;
	}

	function syncHiddenValue() {
		var output = [];

		for (var i = 0; i < rows.length; i++) {
			var groupId = String(rows[i].group_id || '').trim();
			var claimValue = String(rows[i].claim_value || '').trim();

			if (groupId !== '' && claimValue !== '') {
				output.push({
					group_id: groupId,
					claim_value: claimValue
				});
			}
		}

		hiddenInput.value = JSON.stringify(output);
	}

	function rerender() {
		rowsContainer.innerHTML = '';

		for (var i = 0; i < rows.length; i++) {
			var row = rows[i];
			var wrapper = document.createElement('div');
			wrapper.className = 'row g-2 mb-2 align-items-center';
			wrapper.setAttribute('data-uid', row.uid);

			var colSelect = document.createElement('div');
			colSelect.className = 'col-md-4';
			var select = document.createElement('select');
			select.className = 'form-select';
			select.innerHTML = buildSelectOptions(row.uid, row.group_id);
			select.addEventListener('change', (function (uid) {
				return function (event) {
					for (var j = 0; j < rows.length; j++) {
						if (rows[j].uid === uid) {
							rows[j].group_id = String(event.target.value || '');
							break;
						}
					}

					rerender();
				};
			})(row.uid));
			colSelect.appendChild(select);

			var colInput = document.createElement('div');
			colInput.className = 'col-md-6';
			var input = document.createElement('input');
			input.type = 'text';
			input.className = 'form-control';
			input.value = row.claim_value;
			input.addEventListener('input', (function (uid) {
				return function (event) {
					for (var j = 0; j < rows.length; j++) {
						if (rows[j].uid === uid) {
							rows[j].claim_value = String(event.target.value || '');
							break;
						}
					}

					syncHiddenValue();
				};
			})(row.uid));
			colInput.appendChild(input);

			var colActions = document.createElement('div');
			colActions.className = 'col-md-2';
			var removeButton = document.createElement('button');
			removeButton.type = 'button';
			removeButton.className = 'btn btn-outline-danger w-100';
			removeButton.textContent = '{$removeLabel}';
			removeButton.addEventListener('click', (function (uid) {
				return function () {
					rows = rows.filter(function (item) {
						return item.uid !== uid;
					});

					rerender();
				};
			})(row.uid));
			colActions.appendChild(removeButton);

			wrapper.appendChild(colSelect);
			wrapper.appendChild(colInput);
			wrapper.appendChild(colActions);
			rowsContainer.appendChild(wrapper);
		}

		syncHiddenValue();
	}

	addButton.addEventListener('click', function () {
		rows.push({
			uid: 'row_' + (++nextId),
			group_id: '',
			claim_value: ''
		});

		rerender();
	});

	normalizeRows();
	rerender();
}());
</script>
HTML;
	}

	/**
	 * @return array<int, array{id: int, title: string}>
	 */
	private function getGroups(): array
	{
		/** @var DatabaseInterface $db */
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		$query = $db->getQuery(true)
			->select($db->quoteName(['id', 'title']))
			->from($db->quoteName('#__usergroups'))
			->order($db->quoteName('title') . ' ASC');

		$rows = $db->setQuery($query)->loadAssocList();
		$result = [];

		foreach ((array) $rows as $row)
		{
			$id = isset($row['id']) ? (int) $row['id'] : 0;
			$title = isset($row['title']) ? trim((string) $row['title']) : '';

			if ($id <= 0 || $title === '')
			{
				continue;
			}

			$result[] = [
				'id' => $id,
				'title' => $title,
			];
		}

		return $result;
	}

	/**
	 * @return array<int, array{uid: string, group_id: string, claim_value: string}>
	 */
	private function parseMappings(string $rawValue): array
	{
		$rawValue = trim($rawValue);

		if ($rawValue === '')
		{
			return [];
		}

		$decoded = json_decode($rawValue, true);

		if (!is_array($decoded))
		{
			return [];
		}

		$mappings = [];

		foreach ($decoded as $index => $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$groupId = trim((string) ($item['group_id'] ?? ''));
			$claimValue = trim((string) ($item['claim_value'] ?? ''));

			$mappings[] = [
				'uid' => 'row_' . ((int) $index + 1),
				'group_id' => $groupId,
				'claim_value' => $claimValue,
			];
		}

		return $mappings;
	}
}