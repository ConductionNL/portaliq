<?php

/**
 * Portaliq Form Placement Help (help-texts-and-form-help)
 *
 * A form placed on a page is served with the help details its stored form
 * holds. A placement keeps the form's fields from the day the page was made,
 * but the help is read when the page is served, by the placement's `formId`:
 * an editor who changes a form's phone number sees it on the page at once.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Service\CmsRows;

/**
 * Hands each form placement the help details of its stored form.
 *
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md
 */
class FormPlacementHelp {
	private const WIDGET = 'form';

	/**
	 * Constructor.
	 *
	 * @param CmsRows    $rows Reads the portal's stored forms.
	 * @param PortalHelp $help Holds help details to their fixed shape.
	 */
	public function __construct(
		private readonly CmsRows $rows,
		private readonly PortalHelp $help=new PortalHelp(),
	) {
	}//end __construct()

	/**
	 * Set `formHelp` on every form placement, also inside an expanded shared block.
	 *
	 * The stored form wins per key over a `formHelp` an author wrote on the
	 * placement itself. Both pass the same shaping as the portal's details,
	 * so only plain text, an e-mail address and a safe image address leave.
	 * A form of another portal is never read: the forms are asked for by the
	 * serving portal, and a placement naming a foreign form gets no details.
	 * The forms are read once per page, and only when the page has a form.
	 *
	 * @param array  $widgets The shaped page widgets.
	 * @param string $portal  The serving portal's slug.
	 *
	 * @return array The widgets, each form placement with its `formHelp` or without the key.
	 *
	 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
	 */
	public function attach(array $widgets, string $portal): array {
		$forms = null;

		return $this->walk(widgets: $widgets, portal: $portal, forms: $forms);
	}//end attach()

	/**
	 * @param array      $widgets The widgets of a page or of an expanded shared block.
	 * @param string     $portal  The serving portal's slug.
	 * @param array|null $forms   The portal's forms by identifier, read on first use.
	 *
	 * @return array The widgets.
	 */
	private function walk(array $widgets, string $portal, ?array &$forms): array {
		foreach ($widgets as $index => $widget) {
			if (is_array($widget) === false) {
				continue;
			}

			$props = (array)($widget['props'] ?? []);
			if (($widget['widgetKey'] ?? '') === 'sharedBlock' && is_array($props['widgets'] ?? null) === true) {
				$widgets[$index]['props']['widgets'] = $this->walk(widgets: $props['widgets'], portal: $portal, forms: $forms);
				continue;
			}

			if (($widget['widgetKey'] ?? '') !== self::WIDGET) {
				continue;
			}

			$details = $this->help->details(help: ($props['formHelp'] ?? null));
			$formId  = trim((string)($props['formId'] ?? ''));
			if ($formId !== '') {
				if ($forms === null) {
					$forms = $this->formsOf(portal: $portal);
				}

				$details = array_merge($details, $this->help->details(help: ($forms[$formId]['help'] ?? null)));
			}

			unset($props['formHelp']);
			if ($details !== []) {
				$props['formHelp'] = $details;
			}

			$widgets[$index]['props'] = $props;
		}//end foreach

		return $widgets;
	}//end walk()

	/**
	 * The serving portal's forms, under every identifier a row carries.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<string, array> Empty without a portal.
	 */
	private function formsOf(string $portal): array {
		if ($portal === '') {
			return [];
		}

		$forms = [];
		foreach ($this->rows->query(schema: 'form', filters: ['portal' => $portal]) as $row) {
			if ((string)($row['portal'] ?? '') !== $portal) {
				continue;
			}

			$self = (array)($row['@self'] ?? []);
			foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null)] as $id) {
				if ((is_string($id) === true || is_int($id) === true) && (string)$id !== '') {
					$forms[(string)$id] = $row;
				}
			}
		}

		return $forms;
	}//end formsOf()
}//end class
