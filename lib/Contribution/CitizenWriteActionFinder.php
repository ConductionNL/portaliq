<?php

/**
 * Portaliq Citizen Write Action Finder
 *
 * Which contributed action, if any, lets a citizen write on a given register
 * and schema. Only a `type: update` action carrying a sanitised citizen-write
 * declaration counts: an action that never declared one is not a licence to
 * write, whatever else it says.
 *
 * Its own class rather than a method on the registry or on the controller.
 * The registry answers "what has this subject got", the controller answers an
 * HTTP request, and this answers a third question that is neither.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Finds the contributed action a citizen write runs under.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenWriteActionFinder {

	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistry $registry What the subject's apps contribute.
	 */
	public function __construct(
		private readonly PortalContributionRegistry $registry,
	) {
	}//end __construct()

	/**
	 * The action that lets this subject write on this register and schema.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 *
	 * @return array{action: array<string, mixed>, app: string}|null Null when
	 *         no contributed action admits a citizen write here.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function forSubject(array $subject, string $register, string $schema): ?array {
		$aggregate = $this->registry->aggregateFor($subject);
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach (($contribution['actions'] ?? []) as $action) {
				if (($action['type'] ?? '') !== 'update'
					|| ($action['register'] ?? '') !== $register
					|| ($action['schema'] ?? '') !== $schema
					|| is_array(($action[CitizenWriteConfigNormaliser::KEY] ?? null)) === false
				) {
					continue;
				}

				return ['action' => $action, 'app' => (string)($contribution['app'] ?? '')];
			}
		}

		return null;
	}//end forSubject()
}//end class
