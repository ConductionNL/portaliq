<?php

/**
 * Portaliq Mandated Case Reader
 *
 * The case screen's read of a case the person sees because of a mandate
 * (cases-my-cases-page REQ-CMC-004). It asks the same case list reader that
 * lists the mandated cases on "My cases", for the one mandate named, and
 * picks the case out of that list. So the case screen can never open a case
 * the list would not show: the mandate must be held, its party tree must be
 * within the bound, the collection must declare its party field, and the
 * mandate must cover the case type.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Identity\PortalMandateService;
use OCA\Portaliq\Service\Identity\PortalPartyTreeResolver;

/**
 * Reads one case under a named mandate, or nothing.
 *
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
 */
class MandatedCaseReader {
	/**
	 * The `mandate` value that means "acting for yourself".
	 */
	public const ACTING_FOR_SELF = 'self';

	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistry $registry The subject's contributions.
	 * @param PortalCaseListReader $cases Lists the cases a mandate opens.
	 * @param PortalMandateService $mandates The mandates the identity holds.
	 * @param PortalPartyTreeResolver $tree How far a mandate reaches.
	 */
	public function __construct(
		private readonly PortalContributionRegistry $registry,
		private readonly PortalCaseListReader $cases,
		private readonly PortalMandateService $mandates,
		private readonly PortalPartyTreeResolver $tree,
	) {
	}//end __construct()

	/**
	 * The case, as the mandated list shows it, or null.
	 *
	 * A mandate is spent only when it is named: an empty id or "yourself"
	 * opens nothing here, and neither does an id the identity does not hold.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $mandateId The mandate the person acts under.
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
	 */
	public function read(array $subject, string $mandateId, string $register, string $schema, string $id): ?array {
		$mandate = $this->heldMandate(subject: $subject, mandateId: $mandateId);
		if ($mandate === null) {
			return null;
		}

		$described = $this->mandates->describe(mandate: $mandate);
		$party = $described['onBehalfOf'];
		if ($party === '') {
			$party = $described['organisation'];
		}

		$scope = $this->tree->entitiesFor(
			root: $party,
			reachesDown: ($this->mandates->reachOf(mandate: $mandate) === PortalMandateService::REACH_TREE)
		);
		if ($scope['refused'] === true) {
			return null;
		}

		$mandate['_entities'] = $scope['entities'];
		$rows = $this->cases->listMandatedCases(
			subject: $subject,
			aggregate: $this->registry->aggregateFor($subject),
			mandates: [$mandate]
		);

		foreach ($rows as $row) {
			$source = (array)($row['_source'] ?? []);
			if (($source['register'] ?? '') === $register && ($source['schema'] ?? '') === $schema && $this->idOf(row: $row) === $id) {
				return $row;
			}
		}

		return null;
	}//end read()

	/**
	 * The mandate the identity holds under this id, or null.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $mandateId The named mandate.
	 *
	 * @return array<string, mixed>|null
	 */
	private function heldMandate(array $subject, string $mandateId): ?array {
		if ($mandateId === '' || $mandateId === self::ACTING_FOR_SELF) {
			return null;
		}

		$held = $this->mandates->mandatesFor(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? '')
		);

		return $this->mandates->activeMandate(mandates: $held, mandateId: $mandateId);
	}//end heldMandate()

	/**
	 * A row's own id.
	 *
	 * @param array<string, mixed> $row The case row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		$self = ($row['@self'] ?? []);
		$id = ($row['id'] ?? $row['uuid'] ?? null);
		if ($id === null && is_array($self) === true) {
			$id = ($self['id'] ?? $self['uuid'] ?? null);
		}

		return (string)$id;
	}//end idOf()
}//end class
