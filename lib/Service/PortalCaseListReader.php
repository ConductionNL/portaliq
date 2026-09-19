<?php

/**
 * Portaliq Portal Case List Reader
 *
 * "Mijn zaken": every `kind: cases` collection across the subject's
 * contributions, merged into one list and tagged with the app it came from.
 * A collection scoped by a `scopeClaim` reaches the cases a case app attached
 * to the account before its owner ever logged in, which is what makes a case
 * filed at the desk show up on the first login.
 *
 * Every read goes through PortalObjectReader, so the per-row subject, tenant
 * and trust boundary is the same one an ordinary collection read crosses. A
 * collection with no way to scope it is skipped, never read unscoped.
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
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Merges every `kind: cases` collection into the subject's own case list.
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess)             -- PortalSessionService::trustSatisfies
 * is deliberately THE single trust comparator (contract-v2 design decision);
 * every re-check calls it statically so the ordering can never fork. Same
 * reasoning, and the same suppression, as ContentController and
 * ContributionController.
 */
class PortalCaseListReader {
	/**
	 * The collection kind this reader fans out over.
	 */
	public const KIND = 'cases';

	/**
	 * Row cap per case collection.
	 */
	private const ROW_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader The subject-scoped OpenRegister reader.
	 * @param Identity\PortalMandateService|null $mandates Resolves what a
	 *                                                     mandate covers.
	 *                                                     Optional so the
	 *                                                     subject's own cases
	 *                                                     never depend on the
	 *                                                     mandate record.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly ?Identity\PortalMandateService $mandates = null,
	) {
	}//end __construct()

	/**
	 * Every case the subject may see, across every contributing app.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $aggregate The subject's aggregated manifest.
	 *
	 * @return array<int, array<string, mixed>> The merged case rows.
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	public function listCases(array $subject, array $aggregate): array {
		$rows = [];
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			$appId = (string)($contribution['app'] ?? '');
			$label = (string)($contribution['label'] ?? $appId);

			foreach (($contribution['collections'] ?? []) as $collection) {
				if (is_array($collection) === false || ($collection['kind'] ?? '') !== self::KIND) {
					continue;
				}

				if (PortalSessionService::trustSatisfies((string)($subject['trust'] ?? ''), ($collection['minTrust'] ?? null)) === false) {
					// Defence in depth: the aggregate is trust-filtered
					// already, and a case list is exactly the surface where a
					// second check is worth its line.
					continue;
				}

				foreach ($this->readCases(subject: $subject, collection: $collection, contributingApp: $appId) as $row) {
					$row['_source'] = [
						'appId' => $appId,
						'label' => $label,
						'register' => (string)($collection['register'] ?? ''),
						'schema' => (string)($collection['schema'] ?? ''),
						'collection' => (string)($collection['id'] ?? ''),
					];

					$rows[] = $row;
				}
			}//end foreach
		}//end foreach

		usort(
			$rows,
			static function (array $first, array $second): int {
				return strcmp((string)($second['created'] ?? $second['startedAt'] ?? ''), (string)($first['created'] ?? $first['startedAt'] ?? ''));
			}
		);

		return $rows;
	}//end listCases()

	/**
	 * The cases a mandate opens, on top of the subject's own.
	 *
	 * A mandate reads a collection by the party field the collection declares
	 * (`mandateField`), never by the subject field: the whole point is that
	 * these are somebody else's cases, read because a mandate says so. A
	 * collection that declares no party field is skipped, so an app that never
	 * thought about mandates cannot leak its rows through one. Every row names
	 * the mandate that granted it.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $aggregate The subject's aggregated manifest.
	 * @param array<int, array<string, mixed>> $mandates The live mandates.
	 *
	 * @return array<int, array<string, mixed>> The mandated case rows.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function listMandatedCases(array $subject, array $aggregate, array $mandates): array {
		if ($this->mandates === null || $mandates === []) {
			// No mandate recorded is the closed default: none of the
			// organisation's cases, not all of them.
			return [];
		}

		$rows = [];
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			$rows = array_merge($rows, $this->mandatedRowsOfContribution(
				subject: $subject,
				contribution: $contribution,
				mandates: $mandates
			));
		}

		return $rows;
	}//end listMandatedCases()

	/**
	 * The mandated rows one contributing app's case collections yield.
	 *
	 * A collection that declares no party field (`mandateField`) is skipped,
	 * so an app that never thought about mandates cannot leak its rows
	 * through one.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $contribution One app's contribution.
	 * @param array<int, array<string, mixed>> $mandates The live mandates.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	private function mandatedRowsOfContribution(array $subject, array $contribution, array $mandates): array {
		$appId = (string)($contribution['app'] ?? '');
		$label = (string)($contribution['label'] ?? $appId);

		$rows = [];
		foreach (($contribution['collections'] ?? []) as $collection) {
			if (is_array($collection) === false || ($collection['kind'] ?? '') !== self::KIND) {
				continue;
			}

			if (PortalSessionService::trustSatisfies((string)($subject['trust'] ?? ''), ($collection['minTrust'] ?? null)) === false) {
				continue;
			}

			if ((string)($collection['mandateField'] ?? '') === '') {
				continue;
			}

			foreach ($mandates as $mandate) {
				$rows = array_merge($rows, $this->mandatedRowsOfMandate(
					subject: $subject,
					collection: $collection,
					appId: $appId,
					label: $label,
					mandate: $mandate
				));
			}
		}

		return $rows;
	}//end mandatedRowsOfContribution()

	/**
	 * The rows one mandate opens on one collection.
	 *
	 * The entities a mandate reaches are resolved by the caller from the party
	 * tree at request time (REQ-PTV-003). A flat mandate reaches the entity it
	 * names and no other.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $collection The declared case collection.
	 * @param string $appId The contributing app.
	 * @param string $label The contributing app's label.
	 * @param array<string, mixed> $mandate The mandate being spent.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	private function mandatedRowsOfMandate(array $subject, array $collection, string $appId, string $label, array $mandate): array {
		$described = $this->mandates?->describe(mandate: $mandate);
		if ($described === null) {
			return [];
		}

		$party = $described['onBehalfOf'];
		if ($party === '') {
			$party = $described['organisation'];
		}

		if ($party === '') {
			return [];
		}

		$entities = array_values((array)($mandate['_entities'] ?? []));
		if ($entities === []) {
			$entities = [$party];
		}

		$rows = [];
		foreach ($entities as $entity) {
			if ((string)$entity === '') {
				continue;
			}

			$partyRows = $this->readParty(
				collection: $collection,
				contributingApp: $appId,
				mandateField: (string)($collection['mandateField'] ?? ''),
				party: (string)$entity,
				organisation: $described['organisation'],
				audience: (string)($subject['audience'] ?? '')
			);

			$rows = array_merge($rows, $this->stampMandatedRows(
				partyRows: $partyRows,
				collection: $collection,
				source: ['appId' => $appId, 'label' => $label],
				mandate: $mandate,
				described: $described,
				entity: (string)$entity,
				party: $party
			));
		}

		return $rows;
	}//end mandatedRowsOfMandate()

	/**
	 * The rows of one party that this mandate really covers, each stamped
	 * with where it came from and what granted it.
	 *
	 * @param array<int, array<string, mixed>> $partyRows The rows read for the party.
	 * @param array<string, mixed> $collection The declared case collection.
	 * @param array{appId: string, label: string} $source The contributing app.
	 * @param array<string, mixed> $mandate The mandate being spent.
	 * @param array<string, mixed> $described The mandate, described.
	 * @param string $entity The entity the rows belong to.
	 * @param string $party The party the mandate names itself.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	private function stampMandatedRows(
		array $partyRows,
		array $collection,
		array $source,
		array $mandate,
		array $described,
		string $entity,
		string $party,
	): array {
		$rows = [];
		foreach ($partyRows as $row) {
			$caseType = (string)($row[(string)($collection['caseTypeField'] ?? 'caseType')] ?? '');
			if ($this->mandates?->covers(mandate: $mandate, caseType: $caseType) !== true) {
				// A mandate narrower than the organisation lists only what it
				// names.
				continue;
			}

			if ($entity !== $party && $this->reachableThroughAParent(collection: $collection, caseType: $caseType) === false) {
				// REQ-PTV-002: a case type that says nothing about parent
				// access is not reachable that way, whatever the mandate says.
				continue;
			}

			$row['_source'] = [
				'appId' => $source['appId'],
				'label' => $source['label'],
				'register' => (string)($collection['register'] ?? ''),
				'schema' => (string)($collection['schema'] ?? ''),
				'collection' => (string)($collection['id'] ?? ''),
			];
			$row['_mandate'] = $described;
			// The case is the subsidiary's, and says so: it is never presented
			// as the parent's own (REQ-PTV-005).
			$row['_entity'] = $entity;

			$rows[] = $row;
		}

		return $rows;
	}//end stampMandatedRows()

	/**
	 * Whether the contribution declares this case type reachable through a
	 * parent mandate. Silence is a refusal (REQ-PTV-002).
	 *
	 * @param array<string, mixed> $collection The declared case collection.
	 * @param string $caseType The case's type.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	private function reachableThroughAParent(array $collection, string $caseType): bool {
		$declared = ($collection['parentReachableTypes'] ?? []);
		if (is_array($declared) === false || $declared === []) {
			return false;
		}

		return in_array($caseType, $declared, true);
	}//end reachableThroughAParent()

	/**
	 * Read one case collection by the party a mandate names.
	 *
	 * @param array<string, mixed> $collection The declared case collection.
	 * @param string $contributingApp The owning app.
	 * @param string $mandateField The field holding the party.
	 * @param string $party The party the mandate is held for.
	 * @param string $organisation The tenant of the mandate.
	 * @param string $audience The subject's audience.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the parameters are
	 * the scoping boundary itself; folding them away would hide it.
	 */
	private function readParty(
		array $collection,
		string $contributingApp,
		string $mandateField,
		string $party,
		string $organisation,
		string $audience,
	): array {
		return $this->reader->readCollection(
			register: (string)($collection['register'] ?? ''),
			schema: (string)($collection['schema'] ?? ''),
			scopeField: $mandateField,
			subjectRef: $party,
			organisation: $organisation,
			limit: self::ROW_LIMIT,
			contributingApp: $contributingApp,
			audience: $audience,
			fields: ($collection['fields'] ?? null),
			filter: (array)($collection['filter'] ?? [])
		);
	}//end readParty()

	/**
	 * Read one case collection, subject-scoped, or nothing when it cannot be
	 * scoped at all.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $collection The declared case collection.
	 * @param string $contributingApp The owning app, the claim's namespace.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readCases(array $subject, array $collection, string $contributingApp): array {
		$scopeField = (string)($collection['scopeField'] ?? 'subjectRef');
		$scopeClaim = (string)($collection['scopeClaim'] ?? '');
		$via = ($collection['via'] ?? null);
		$subjectRef = (string)($subject['subjectRef'] ?? '');

		// Fail closed, for the same reason PortalInboxReader does: a case list
		// with nothing to scope it by is every subject's cases, not none.
		if ($subjectRef === '' || ($scopeField === '' && $scopeClaim === '' && $via === null)) {
			return [];
		}

		return $this->reader->readCollection(
			register: (string)($collection['register'] ?? ''),
			schema: (string)($collection['schema'] ?? ''),
			scopeField: $scopeField,
			subjectRef: $subjectRef,
			organisation: (string)($subject['organisation'] ?? ''),
			limit: self::ROW_LIMIT,
			scopeClaim: $scopeClaim,
			contributingApp: $contributingApp,
			via: $via,
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null),
			filter: (array)($collection['filter'] ?? [])
		);
	}//end readCases()
}//end class
