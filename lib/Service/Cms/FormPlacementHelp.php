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
		return $this->walk(widgets: $widgets, run: new FormPlacementRun(portal: $portal));
	}//end attach()

	/**
	 * Attach form help to the widgets of a page or of an expanded shared block.
	 *
	 * @param array            $widgets The widgets.
	 * @param FormPlacementRun $run     The serving portal and its forms, read on first use.
	 *
	 * @return array The widgets.
	 */
	private function walk(array $widgets, FormPlacementRun $run): array {
		foreach ($widgets as $index => $widget) {
			if (is_array($widget) === true) {
				$widgets[$index] = $this->placed(widget: $widget, run: $run);
			}
		}

		return $widgets;
	}//end walk()

	/**
	 * One widget: a shared block walked into, a form placement with its help, anything else as it is.
	 *
	 * @param array            $widget The widget.
	 * @param FormPlacementRun $run    The serving portal and its forms.
	 *
	 * @return array The widget.
	 */
	private function placed(array $widget, FormPlacementRun $run): array {
		$props = (array)($widget['props'] ?? []);
		if ($this->isSharedBlock(widget: $widget, props: $props) === true) {
			$widget['props']['widgets'] = $this->walk(widgets: $props['widgets'], run: $run);
			return $widget;
		}

		if (($widget['widgetKey'] ?? '') === self::WIDGET) {
			$widget['props'] = $this->withFormHelp(props: $props, run: $run);
		}

		return $widget;
	}//end placed()

	/**
	 * Whether a widget is a shared block with widgets to walk into.
	 *
	 * @param array $widget The widget.
	 * @param array $props  Its props.
	 *
	 * @return bool
	 */
	private function isSharedBlock(array $widget, array $props): bool {
		return ($widget['widgetKey'] ?? '') === 'sharedBlock' && is_array($props['widgets'] ?? null) === true;
	}//end isSharedBlock()

	/**
	 * A form placement's props with `formHelp`: its own, the stored form's winning per key; none when empty.
	 *
	 * @param array            $props The placement's props.
	 * @param FormPlacementRun $run   The serving portal and its forms.
	 *
	 * @return array The props.
	 */
	private function withFormHelp(array $props, FormPlacementRun $run): array {
		$details = $this->help->details(help: ($props['formHelp'] ?? null));
		$formId  = trim((string)($props['formId'] ?? ''));
		if ($formId !== '') {
			$details = array_merge($details, $this->storedHelp(formId: $formId, run: $run));
		}

		unset($props['formHelp']);
		if ($details !== []) {
			$props['formHelp'] = $details;
		}

		return $props;
	}//end withFormHelp()

	/**
	 * The help a stored form of the serving portal holds; the forms are read at the first ask.
	 *
	 * @param string           $formId The placement's form identifier.
	 * @param FormPlacementRun $run    The serving portal and its forms.
	 *
	 * @return array<string, string>
	 */
	private function storedHelp(string $formId, FormPlacementRun $run): array {
		if ($run->forms === null) {
			$run->forms = $this->formsOf(portal: $run->portal);
		}

		return $this->help->details(help: ($run->forms[$formId]['help'] ?? null));
	}//end storedHelp()

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
			if ((string)($row['portal'] ?? '') === $portal) {
				$forms = $this->keyedBy(forms: $forms, row: $row);
			}
		}

		return $forms;
	}//end formsOf()

	/**
	 * The forms with one row added under each identifier it carries.
	 *
	 * @param array<string, array> $forms The forms so far.
	 * @param array                $row   The form row.
	 *
	 * @return array<string, array>
	 */
	private function keyedBy(array $forms, array $row): array {
		foreach ($this->idCandidates(row: $row) as $id) {
			if ((is_string($id) === true || is_int($id) === true) && (string)$id !== '') {
				$forms[(string)$id] = $row;
			}
		}

		return $forms;
	}//end keyedBy()

	/**
	 * A row's `id` and `uuid`, then its `@self` id and uuid.
	 *
	 * @param array $row The form row.
	 *
	 * @return array<int, mixed>
	 */
	private function idCandidates(array $row): array {
		$self = $this->selfOf(row: $row);

		return [($row['id'] ?? null), ($row['uuid'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null)];
	}//end idCandidates()

	/**
	 * A row's `@self`, as an array.
	 *
	 * @param array $row The form row.
	 *
	 * @return array
	 */
	private function selfOf(array $row): array {
		return (array)($row['@self'] ?? []);
	}//end selfOf()
}//end class
