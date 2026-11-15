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
 * @spec openspec/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\CaseStatusLabelField;
use OCA\Portaliq\Kernel\Contracts\PortalCaseListReaderInterface;
use OCA\Portaliq\Kernel\Support\PortalBranchScope;
use OCA\Portaliq\Service\Identity\MandateParties;
use OCA\Portaliq\Kernel\Support\SessionTrust;

/**
 * Merges every `kind: cases` collection into the subject's own case list.
 *
 * @spec openspec/specs/portal-identity-space/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess)             -- SessionTrust::trustSatisfies
 * is deliberately THE single trust comparator (contract-v2 design decision);
 * every re-check calls it statically so the ordering can never fork. Same
 * reasoning, and the same suppression, as ContentController and
 * ContributionController.
 */
class PortalCaseListReader implements PortalCaseListReaderInterface {
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
	 * @param ScopedCollectionReaderInterface $reader The subject-scoped OpenRegister reader.
	 * @param Identity\PortalMandateService|null $mandates Resolves what a
	 *                                                     mandate covers.
	 *                                                     Optional so the
	 *                                                     subject's own cases
	 *                                                     never depend on the
	 *                                                     mandate record.
	 * @param PortalBranchScope $branches The branch filter of signin-eherkenning-branch.
	 * @param CaseTypeNames|null $typeNames Names each row's case type
	 *                                      (site-mijn-omgeving-components
	 *                                      REQ-SMO-030); without it rows
	 *                                      carry no name.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly ?Identity\PortalMandateService $mandates = null,
		private readonly PortalBranchScope $branches = new PortalBranchScope(),
		private readonly ?CaseTypeNames $typeNames = null,
	) {
	}//end __construct()

	/**
	 * Every case the subject may see, across every contributing app.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $aggregate The subject's aggregated manifest.
	 * @param array<int, string> $hiddenCaseTypes The case type ids the serving
	 *                                            portal does not show.
	 *
	 * @return array<int, array<string, mixed>> The merged case rows.
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function listCases(array $subject, array $aggregate, array $hiddenCaseTypes = []): array {
		$run  = new CaseListRun(subject: $subject, hidden: $hiddenCaseTypes);
		$rows = [];
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			$rows = array_merge($rows, $this->ownRowsOfContribution(run: $run, contribution: $contribution));
		}

		return $this->newestFirst(rows: $rows);
	}//end listCases()

	/**
	 * The subject's own case rows one contributing app's case collections
	 * yield.
	 *
	 * @param CaseListRun          $run          The listing.
	 * @param array<string, mixed> $contribution One app's contribution.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ownRowsOfContribution(CaseListRun $run, array $contribution): array {
		$rows = [];
		foreach (($contribution['collections'] ?? []) as $collection) {
			if ($this->isListedCollection(run: $run, collection: $collection) === false) {
				continue;
			}

			$source = new CaseListSource(contribution: $contribution, collection: $collection);
			$rows   = array_merge($rows, $this->ownRowsOfCollection(run: $run, source: $source));
		}

		return $rows;
	}//end ownRowsOfContribution()

	/**
	 * Whether a collection is a case collection the subject's trust meets.
	 *
	 * Defence in depth: the aggregate is trust-filtered already, and a case
	 * list is exactly the surface where a second check is worth its line.
	 *
	 * @param CaseListRun $run        The listing.
	 * @param mixed       $collection One declared collection.
	 *
	 * @return bool
	 */
	private function isListedCollection(CaseListRun $run, mixed $collection): bool {
		if (is_array($collection) === false || ($collection['kind'] ?? '') !== self::KIND) {
			return false;
		}

		return SessionTrust::trustSatisfies((string)($run->subject['trust'] ?? ''), ($collection['minTrust'] ?? null));
	}//end isListedCollection()

	/**
	 * The subject's own rows of one case collection, the hidden case types
	 * left out, each stamped with where it came from.
	 *
	 * @param CaseListRun    $run    The listing.
	 * @param CaseListSource $source The case collection and its app.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ownRowsOfCollection(CaseListRun $run, CaseListSource $source): array {
		$rows = [];
		foreach ($this->readCases(subject: $run->subject, collection: $source->collection, contributingApp: $source->appId) as $row) {
			if (in_array($this->typeOf(row: $row, collection: $source->collection), $run->hidden, true) === true) {
				continue;
			}

			$rows[] = $this->stampedRow(row: $row, source: $source);
		}

		return $rows;
	}//end ownRowsOfCollection()

	/**
	 * A case row stamped with its source, whether it is closed, its status
	 * label and its case type's name.
	 *
	 * @param array<string, mixed> $row    The case row.
	 * @param CaseListSource       $source The case collection and its app.
	 *
	 * @return array<string, mixed>
	 */
	private function stampedRow(array $row, CaseListSource $source): array {
		$collection = $source->collection;
		$caseType   = $this->typeOf(row: $row, collection: $collection);

		$row['_source'] = $source->stamp();
		$row['_closed'] = (new CaseRowMarker())->isClosed(row: $row, collection: $collection);
		$row = (new CaseStatusLabelField())->stamp(row: $row, collection: $collection);
		return ($this->typeNames?->stamp(row: $row, collection: $collection, typeId: $caseType) ?? $row);
	}//end stampedRow()

	/**
	 * The rows, newest first.
	 *
	 * @param array<int, array<string, mixed>> $rows The case rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function newestFirst(array $rows): array {
		$marker = new CaseRowMarker();
		usort(
			$rows,
			static function (array $first, array $second) use ($marker): int {
				return strcmp($marker->dateOf(row: $second), $marker->dateOf(row: $first));
			}
		);

		return $rows;
	}//end newestFirst()

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
	 * @param array<int, string> $hiddenCaseTypes The case type ids the serving
	 *                                            portal does not show.
	 *
	 * @return array<int, array<string, mixed>> The mandated case rows.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function listMandatedCases(array $subject, array $aggregate, array $mandates, array $hiddenCaseTypes = []): array {
		if ($this->mandates === null || $mandates === []) {
			// No mandate recorded is the closed default: none of the
			// organisation's cases, not all of them.
			return [];
		}

		$run = new CaseListRun(subject: $subject, hidden: $hiddenCaseTypes);
		$run->mandates = $mandates;
		$rows = [];
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			$rows = array_merge($rows, $this->mandatedRowsOfContribution(run: $run, contribution: $contribution));
		}

		return $rows;
	}//end listMandatedCases()

	/**
	 * The case type id of a row, from the collection's `caseTypeField`: a
	 * plain id, or a reference object carrying `id` or `uuid`.
	 *
	 * @param array<string, mixed> $row The case row.
	 * @param array<string, mixed> $collection The declared `cases` collection.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	private function typeOf(array $row, array $collection): string {
		$value = ($row[(string)($collection['caseTypeField'] ?? 'caseType')] ?? '');
		if (is_array($value) === true) {
			$value = ($value['id'] ?? $value['uuid'] ?? '');
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end typeOf()

	/**
	 * The mandated rows one contributing app's case collections yield.
	 *
	 * A collection that declares no party field (`mandateField`) is skipped,
	 * so an app that never thought about mandates cannot leak its rows
	 * through one.
	 *
	 * @param CaseListRun          $run          The mandated listing.
	 * @param array<string, mixed> $contribution One app's contribution.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	private function mandatedRowsOfContribution(CaseListRun $run, array $contribution): array {
		$rows = [];
		foreach (($contribution['collections'] ?? []) as $collection) {
			if ($this->isListedCollection(run: $run, collection: $collection) === false) {
				continue;
			}

			if ((string)($collection['mandateField'] ?? '') === '') {
				continue;
			}

			$source = new CaseListSource(contribution: $contribution, collection: $collection);
			$rows   = array_merge($rows, $this->mandatedRowsOfCollection(run: $run, source: $source));
		}

		return $rows;
	}//end mandatedRowsOfContribution()

	/**
	 * The rows every live mandate opens on one case collection, the hidden
	 * case types left out.
	 *
	 * @param CaseListRun    $run    The mandated listing.
	 * @param CaseListSource $source The case collection and its app.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function mandatedRowsOfCollection(CaseListRun $run, CaseListSource $source): array {
		$rows = [];
		foreach ($run->mandates as $mandate) {
			foreach ($this->mandatedRowsOfMandate(run: $run, source: $source, mandate: $mandate) as $row) {
				if (in_array($this->typeOf(row: $row, collection: $source->collection), $run->hidden, true) === false) {
					$rows[] = $row;
				}
			}
		}

		return $rows;
	}//end mandatedRowsOfCollection()

	/**
	 * The rows one mandate opens on one collection.
	 *
	 * The entities a mandate reaches are resolved by the caller from the party
	 * tree at request time (REQ-PTV-003). A flat mandate reaches the entity it
	 * names and no other.
	 *
	 * @param CaseListRun          $run     The mandated listing.
	 * @param CaseListSource       $source  The case collection and its app.
	 * @param array<string, mixed> $mandate The mandate being spent.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	private function mandatedRowsOfMandate(CaseListRun $run, CaseListSource $source, array $mandate): array {
		$described = $this->mandates?->describe(mandate: $mandate);
		if ($described === null) {
			return [];
		}

		$read = new MandatedRead(source: $source, mandate: $mandate);
		$read->described = $described;
		$read->party     = $this->mandateParty(described: $described);
		if ($read->party === '') {
			return [];
		}

		$rows = [];
		foreach ($this->reachedEntities(mandate: $mandate, party: $read->party) as $entity) {
			if ((string)$entity === '') {
				continue;
			}

			$read->entity = (string)$entity;
			$rows = array_merge($rows, $this->mandatedRowsOfEntity(run: $run, read: $read));
		}

		return $rows;
	}//end mandatedRowsOfMandate()

	/**
	 * The rows one entity a mandate reaches holds in the collection, those
	 * the mandate covers, stamped.
	 *
	 * @param CaseListRun  $run  The mandated listing.
	 * @param MandatedRead $read The mandate's read, at the entity being read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function mandatedRowsOfEntity(CaseListRun $run, MandatedRead $read): array {
		$collection = $read->source->collection;
		$partyRows  = $this->readParty(
			collection: $collection,
			contributingApp: $read->source->appId,
			mandateField: (string)($collection['mandateField'] ?? ''),
			party: $read->entity,
			organisation: $read->described['organisation'],
			audience: (string)($run->subject['audience'] ?? '')
		);

		return $this->stampMandatedRows(partyRows: $partyRows, read: $read);
	}//end mandatedRowsOfEntity()

	/**
	 * The party a described mandate is held for: the entity it names, else
	 * its tenant.
	 *
	 * @param array<string, mixed> $described The mandate, described.
	 *
	 * @return string
	 */
	private function mandateParty(array $described): string {
		$party = $described['onBehalfOf'];
		if ($party === '') {
			$party = $described['organisation'];
		}

		return $party;
	}//end mandateParty()

	/**
	 * The entities a mandate reaches, resolved by the caller from the party
	 * tree; a flat mandate reaches the party it names and no other.
	 *
	 * @param array<string, mixed> $mandate The mandate being spent.
	 * @param string               $party   The party it is held for.
	 *
	 * @return array<int, mixed>
	 */
	private function reachedEntities(array $mandate, string $party): array {
		$entities = array_values((array)($mandate['_entities'] ?? []));
		if ($entities === []) {
			return [$party];
		}

		return $entities;
	}//end reachedEntities()

	/**
	 * The rows of one party that this mandate really covers, each stamped
	 * with where it came from and what granted it.
	 *
	 * @param array<int, array<string, mixed>> $partyRows The rows read for the entity.
	 * @param MandatedRead                     $read      The mandate's read, at the entity being read.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	private function stampMandatedRows(array $partyRows, MandatedRead $read): array {
		$rows = [];
		foreach ($partyRows as $row) {
			if ($this->isCoveredRow(read: $read, row: $row) === false) {
				continue;
			}

			$row = $this->stampedRow(row: $row, source: $read->source);
			$row['_mandate'] = $read->described;
			// The case is the subsidiary's, and says so: it is never presented
			// as the parent's own (REQ-PTV-005).
			$row['_entity'] = $read->entity;

			$rows[] = $row;
		}

		return $rows;
	}//end stampMandatedRows()

	/**
	 * Whether the mandate really covers a row of the entity being read.
	 *
	 * @param MandatedRead         $read The mandate's read, at the entity being read.
	 * @param array<string, mixed> $row  The row read for the entity.
	 *
	 * @return bool
	 */
	private function isCoveredRow(MandatedRead $read, array $row): bool {
		$collection = $read->source->collection;
		$caseType   = $this->typeOf(row: $row, collection: $collection);
		if ($this->mandates?->covers(mandate: $read->mandate, caseType: $caseType) !== true) {
			// A mandate narrower than the organisation lists only what it
			// names.
			return false;
		}

		// REQ-PTV-002: a case type that says nothing about parent access is
		// not reachable that way, whatever the mandate says.
		return $read->entity === $read->party || $this->reachableThroughAParent(collection: $collection, caseType: $caseType) === true;
	}//end isCoveredRow()

	/**
	 * Whether the contribution declares this case type reachable through a
	 * parent mandate. Silence is a refusal (REQ-PTV-002).
	 *
	 * @param array<string, mixed> $collection The declared case collection.
	 * @param string $caseType The case's type.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
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
	 *
	 * @spec openspec/specs/portal-mandates/spec.md#requirement-a-mandated-case-list-reads-the-party-in-its-typed-and-its-bare-form-req-mtp-001
	 */
	private function readParty(
		array $collection,
		string $contributingApp,
		string $mandateField,
		string $party,
		string $organisation,
		string $audience,
	): array {
		// A case app stores the party in the typed form a mandate names it in
		// (`subject:<ref>`, `kvk:<8 digits>`); one written before the typed
		// form stores it bare. Both are read, and a case found both ways is
		// listed once (mandate-reads-the-typed-party REQ-MTP-001).
		$forms = array_values(array_unique([(new MandateParties())->typed(value: $party), $party]));
		$reads = array_map(
			fn (string $form): array => $this->reader->readCollection(
				register: (string)($collection['register'] ?? ''),
				schema: (string)($collection['schema'] ?? ''),
				scopeField: $mandateField,
				subjectRef: $form,
				organisation: $organisation,
				limit: self::ROW_LIMIT,
				contributingApp: $contributingApp,
				audience: $audience,
				fields: ($collection['fields'] ?? null),
				filter: (array)($collection['filter'] ?? [])
			),
			$forms
		);

		return (new UniqueCaseRows())->merged(lists: $reads);
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

		$rows = $this->reader->readCollection(
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

		// Change signin-eherkenning-branch D2: a branch session sees its branch only.
		return $this->branches->rows(subject: $subject, collection: $collection, rows: $rows);
	}//end readCases()
}//end class
