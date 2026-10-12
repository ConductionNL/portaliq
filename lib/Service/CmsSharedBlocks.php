<?php

/**
 * Portaliq CMS Shared Blocks (site-shared-page-blocks)
 *
 * Shapes a page's widgets and expands the shared blocks placed on it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Cms\FormPlacementHelp;

/**
 * The widgets of a page, with each placed shared block expanded for its organisation.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */
class CmsSharedBlocks {
	/**
	 * Hands a form placement the help details of its stored form.
	 *
	 * @var FormPlacementHelp
	 */
	private readonly FormPlacementHelp $formHelp;

	/**
	 * Constructor.
	 *
	 * @param CmsRows                $rows     Reads the shared block rows.
	 * @param FormPlacementHelp|null $formHelp Hands a form placement its stored form's help; built on the same rows when left out.
	 */
	public function __construct(
		private readonly CmsRows $rows,
		?FormPlacementHelp $formHelp=null,
	) {
		$this->formHelp = ($formHelp ?? new FormPlacementHelp(rows: $rows));
	}//end __construct()

	/**
	 * Put the widgets of each placed shared block into the placement.
	 *
	 * A block expands only when it is published and belongs to the serving
	 * portal's organisation. A foreign, unpublished or missing block all
	 * answer the same way, with no widgets and `unavailable`, so the answer is
	 * not an existence oracle for another organisation's blocks. A placement
	 * inside a block is not expanded again.
	 *
	 * Last, every form placement, on the page or inside an expanded block,
	 * gets the help details of its stored form (help-texts-and-form-help).
	 *
	 * @param array  $widgets      The shaped page widgets.
	 * @param string $organisation The serving portal's organisation.
	 * @param string $portal       The serving portal's slug, whose forms a placement may name.
	 *
	 * @return array The widgets, placements expanded.
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t02
	 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
	 */
	public function expand(array $widgets, string $organisation, string $portal=''): array {
		$blocks = null;
		foreach ($widgets as $index => $widget) {
			if ($widget['widgetKey'] !== 'sharedBlock') {
				continue;
			}

			if ($blocks === null) {
				$blocks = $this->publishedBlocks(organisation: $organisation);
			}

			$id  = trim((string)($widget['props']['block'] ?? ''));
			$row = ($blocks[$id] ?? null);

			$widgets[$index]['props'] = ['block' => $id, 'widgets' => [], 'unavailable' => true];
			if ($row !== null) {
				$inner = array_values(
					array_filter(
						$this->shapeWidgets(raw: (array)($row['widgets'] ?? [])),
						static fn (array $inner): bool => $inner['widgetKey'] !== 'sharedBlock'
					)
				);
				$widgets[$index]['props'] = ['block' => $id, 'widgets' => $inner, 'unavailable' => false];
			}
		}

		return $this->formHelp->attach(widgets: $widgets, portal: $portal);
	}//end expand()

	/**
	 * Shape stored widget entries to the API shape, in grid order.
	 *
	 * @param array $raw The stored entries.
	 *
	 * @return array The entries.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function shapeWidgets(array $raw): array {
		$widgets = [];
		foreach ($raw as $widget) {
			if (is_array($widget) === false) {
				continue;
			}

			$widgets[] = [
				'id'         => (string)($widget['id'] ?? ''),
				'widgetKey'  => (string)($widget['widgetKey'] ?? ''),
				'slot'       => (string)($widget['slot'] ?? 'body'),
				'gridX'      => (int)($widget['gridX'] ?? 0),
				'gridY'      => (int)($widget['gridY'] ?? 0),
				'gridWidth'  => (int)($widget['gridWidth'] ?? 12),
				'gridHeight' => (int)($widget['gridHeight'] ?? 4),
				'props'      => (array)($widget['props'] ?? []),
			];
		}

		usort(
			$widgets,
			static fn ($a, $b) => [$a['gridY'], $a['gridX']] <=> [$b['gridY'], $b['gridX']]
		);

		return $widgets;
	}//end shapeWidgets()

	/**
	 * The published shared blocks of an organisation, by id.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return array<string, array> Empty for no organisation.
	 */
	private function publishedBlocks(string $organisation): array {
		if ($organisation === '') {
			return [];
		}

		$blocks = [];
		foreach ($this->rows->query(schema: 'sharedBlock', filters: ['organisation' => $organisation, 'status' => 'published']) as $row) {
			$id = $this->rows->rowId(row: $row);
			if ($id !== null && ($row['status'] ?? '') === 'published' && (string)($row['organisation'] ?? '') === $organisation) {
				$blocks[$id] = $row;
			}
		}

		return $blocks;
	}//end publishedBlocks()
}//end class
