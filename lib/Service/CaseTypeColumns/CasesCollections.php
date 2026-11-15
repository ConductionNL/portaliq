<?php

/**
 * Portaliq cases collections
 *
 * The `cases` collections the contributing apps declare, for every audience
 * any app serves.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\CaseTypeColumns
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\CaseTypeColumns;

use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;

/**
 * Reads the declared `cases` collections from the contribution registry.
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */
class CasesCollections {
	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistryInterface $registry The contributing apps.
	 */
	public function __construct(
		private readonly PortalContributionRegistryInterface $registry,
	) {
	}//end __construct()

	/**
	 * Every declared `cases` collection.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
	 */
	public function all(): array {
		$found = [];
		foreach ($this->registry->servedAudiences() as $audience) {
			$aggregate = $this->registry->aggregateFor(
				subject: ['audience' => $audience, 'trust' => 'high', 'subjectRef' => '', 'organisation' => '']
			);
			foreach ((array)($aggregate['contributions'] ?? []) as $contribution) {
				$found = array_merge($found, $this->casesOf(contribution: $contribution));
			}
		}

		return $found;
	}//end all()

	/**
	 * The `cases` collections of one contribution.
	 *
	 * @param mixed $contribution One app's contribution.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
	 */
	private function casesOf(mixed $contribution): array {
		if (is_array($contribution) === false) {
			return [];
		}

		return array_values(
			array_filter(
				(array)($contribution['collections'] ?? []),
				fn ($collection): bool => is_array($collection) === true && ($collection['kind'] ?? '') === 'cases'
			)
		);
	}//end casesOf()
}//end class
