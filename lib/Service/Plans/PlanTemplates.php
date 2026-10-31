<?php

/**
 * Portaliq Plan Templates (shared-plans-with-a-caseworker)
 *
 * The plan templates a portal publishes.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Plans;

use OCA\Portaliq\Service\PortalObjectReader;

/**
 * Reads the published plan templates of a portal.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
 */
class PlanTemplates {
	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader The scoped reader.
	 * @param PlanRules          $rules  The plan rules, for the row id.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PlanRules $rules = new PlanRules(),
	) {
	}//end __construct()

	/**
	 * The published templates of a portal.
	 *
	 * @param string $portal The portal's slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
	 */
	public function published(string $portal): array {
		if ($portal === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: PortalPlanService::REGISTER,
			schema: 'portalPlanTemplate',
			scopeField: 'portal',
			subjectRef: $portal,
			organisation: '',
			limit: 100
		);

		return array_values(array_filter($rows, static fn (array $row): bool => ($row['published'] ?? false) === true));
	}//end published()

	/**
	 * One published template of a portal.
	 *
	 * @param string $portal The portal's slug.
	 * @param string $id The template.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
	 */
	public function byId(string $portal, string $id): ?array {
		foreach ($this->published(portal: $portal) as $row) {
			if ($this->rules->idOf(row: $row) === $id) {
				return $row;
			}
		}

		return null;
	}//end byId()

	/**
	 * The name a new plan starts with: the one given, else the template's; '' when there is none or it is too long.
	 *
	 * @param array<string, mixed>|null $template The template the plan starts from, or null.
	 * @param string                    $title    The name the resident gave.
	 *
	 * @return string The name, or ''.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md
	 */
	public function startTitle(?array $template, string $title): string {
		$title = trim($title);
		if ($title === '' && $template !== null) {
			$title = (string)($template['title'] ?? '');
		}

		if (mb_strlen($title) > 200) {
			return '';
		}

		return $title;
	}//end startTitle()
}//end class
