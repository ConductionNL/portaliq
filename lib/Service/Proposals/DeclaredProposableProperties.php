<?php

/**
 * Portaliq Declared Proposable Properties
 *
 * The properties a record may take a change proposal on, as the contributing
 * apps declare them: never as a caller names them.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Proposals
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
 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Proposals;

use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;

/**
 * Reads the `propose-change` actions the contributions declare for a register
 * and schema.
 *
 * A portal subject is held to the action its own contribution carries
 * (`matchIn()` over its aggregate). A colleague has no audience of their own,
 * so for them the declarations of every audience are joined (`forRecord()`).
 * An action with an empty or missing `proposable` list allows nothing.
 *
 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md
 */
class DeclaredProposableProperties {

	/**
	 * The action type that declares proposable properties.
	 */
	public const ACTION_TYPE = 'propose-change';

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
	 * The proposable properties declared for a register and schema, joined
	 * across every audience a contributing app serves, in declaration order
	 * and each once.
	 *
	 * @param string $register The record's register.
	 * @param string $schema   The record's schema.
	 *
	 * @return array<int, string> The properties; empty when nothing declares a propose-change there.
	 *
	 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md#requirement-the-colleague-route-takes-the-allow-list-from-the-server-req-cpc-003
	 */
	public function forRecord(string $register, string $schema): array {
		if ($register === '' || $schema === '') {
			return [];
		}

		$properties = [];
		foreach ($this->registry->servedAudiences() as $audience) {
			// The highest trust, as the other readers across audiences ask:
			// the colleague's own right on the record is the read gate.
			$aggregate = $this->registry->aggregateFor(
				subject: ['audience' => $audience, 'trust' => 'high', 'subjectRef' => '', 'organisation' => '']
			);
			foreach ($this->matchIn(aggregate: $aggregate, register: $register, schema: $schema) as $match) {
				$properties = array_merge($properties, $match['proposable']);
			}
		}

		return array_values(array_unique($properties));
	}//end forRecord()

	/**
	 * Every `propose-change` action in one aggregate that declares a
	 * non-empty allow-list for the register and schema, in order, each with
	 * the string entries of that list.
	 *
	 * @param array<string, mixed> $aggregate The aggregate of the contributions.
	 * @param string               $register  The record's register.
	 * @param string               $schema    The record's schema.
	 *
	 * @return array<int, array{action: array<string, mixed>, app: string, proposable: array<int, string>}>
	 *
	 * @spec openspec/changes/change-proposals-reach-the-case-page/specs/change-proposal-queue/spec.md#requirement-the-colleague-route-takes-the-allow-list-from-the-server-req-cpc-003
	 */
	public function matchIn(array $aggregate, string $register, string $schema): array {
		$matches = [];
		foreach ((array)($aggregate['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			foreach ((array)($contribution['actions'] ?? []) as $action) {
				$proposable = $this->declared(action: $action, register: $register, schema: $schema);
				if ($proposable === null) {
					continue;
				}

				$matches[] = [
					'action' => $action,
					'app' => (string)($contribution['app'] ?? ''),
					'proposable' => array_values(array_filter($proposable, static fn (mixed $entry): bool => is_string($entry) === true)),
				];
			}
		}

		return $matches;
	}//end matchIn()

	/**
	 * An action's allow-list when it is a propose-change on this register and
	 * schema with a non-empty list, else null. An action with an empty
	 * allow-list proposes nothing: silence is not permission.
	 *
	 * @param mixed  $action   One declared action.
	 * @param string $register The record's register.
	 * @param string $schema   The record's schema.
	 *
	 * @return array<mixed>|null
	 */
	private function declared(mixed $action, string $register, string $schema): ?array {
		if (is_array($action) === false || ($action['type'] ?? '') !== self::ACTION_TYPE) {
			return null;
		}

		if (($action['register'] ?? '') !== $register || ($action['schema'] ?? '') !== $schema) {
			return null;
		}

		$proposable = ($action['proposable'] ?? []);
		if (is_array($proposable) === false || $proposable === []) {
			return null;
		}

		return $proposable;
	}//end declared()
}//end class
