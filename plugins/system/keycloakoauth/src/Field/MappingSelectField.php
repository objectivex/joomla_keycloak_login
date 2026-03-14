<?php

namespace Joomla\Plugin\System\Keycloakoauth\Field;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

class MappingSelectField extends FormField
{
	protected $type = 'MappingSelect';

	protected function getInput(): string
	{
		$id    = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
		$name  = htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8');
		$value = (string) $this->value;

		$emptyLabel = htmlspecialchars(Text::_('PLG_SYSTEM_KEYCLOAKOAUTH_MAPPING_NO_SELECTION'), ENT_QUOTES, 'UTF-8');
		$options    = '<option value="">' . $emptyLabel . '</option>';

		if ($value !== '')
		{
			$safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
			$options  .= '<option value="' . $safeValue . '" selected="selected">' . $safeValue . '</option>';
		}

		return '<select id="' . $id . '" name="' . $name . '" class="form-select">' . $options . '</select>';
	}
}