<?php

namespace Joomla\Plugin\System\Keycloakoauth\Field;

use Joomla\CMS\Form\FormField;

\defined('_JEXEC') or die;

class PersistedCheckboxField extends FormField
{
	protected $type = 'PersistedCheckbox';

	protected function getInput(): string
	{
		$id = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
		$name = htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8');
		$value = (string) $this->value;
		$checked = in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true) ? ' checked="checked"' : '';

		return '<input type="hidden" name="' . $name . '" value="0">'
			. '<div class="form-check">'
			. '<input class="form-check-input" type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . $checked . '>'
			. '</div>';
	}
}