<?php

/**
 * Portaliq Portal Case Type Choices
 *
 * The case types a portal serves, each with the title a resident reads, for
 * a choice such as "Alleen deze soorten zaken" when someone is authorised.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Service\CaseTypeReader;

/**
 * Reads only the case types the portal's own published bindings declare,
 * never one a caller names, so the list cannot reach another tenant's types.
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
 */
class PortalCaseTypeChoices {

	/**
	 * Constructor.
	 *
	 * @param PortalFormBindingResolver $bindings The case types a portal declares.
	 * @param CaseTypeReader $caseTypes Reads a case type's title.
	 */
	public function __construct(
		private readonly PortalFormBindingResolver $bindings,
		private readonly CaseTypeReader $caseTypes,
	) {
	}//end __construct()

	/**
	 * The portal's case types, once each, sorted by title.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array{id: string, title: string}> The choices; a case type that cannot be read is left out.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
	 */
	public function forPortal(string $portal): array {
		$choices = [];
		foreach ($this->bindings->declaredCaseTypes(portal: $portal) as [$register, $schema, $id]) {
			if (isset($choices[$id]) === true) {
				continue;
			}

			$type  = $this->caseTypes->readCaseType(register: $register, schema: $schema, id: $id);
			$title = trim((string)($type['title'] ?? $type['name'] ?? ''));
			if ($type === null || $title === '') {
				continue;
			}

			$choices[$id] = ['id' => $id, 'title' => $title];
		}

		$choices = array_values($choices);
		usort($choices, static fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

		return $choices;
	}//end forPortal()
}//end class
