<?php

/**
 * Portaliq Row Screen
 *
 * The one proof that a subject may see one row by its id: the scoped read,
 * then the tenant, waiting-row, branch and hidden-case-type gates the list
 * applies. Every per-object portal route proves its row here, so a known id
 * never reaches a row the list would not show.
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
 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Branch\PortalBranchScope;
use OCA\Portaliq\Service\Tenancy\SchemaTenancy;
use OCP\IRequest;

/**
 * Proves one row visible to a subject the way the collection's screen does.
 *
 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t03
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- SchemaTenancy::isOrganisationScoped, the one tenancy rule.
 */
class PortalRowScreen {
	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader      $reader    The scoped single-object read.
	 * @param CaseTypeVisibility|null $caseTypes The case types the serving portal hides; absent hides nothing.
	 * @param PortalBranchScope       $branches  The branch filter of signin-eherkenning-branch.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly ?CaseTypeVisibility $caseTypes = null,
		private readonly PortalBranchScope $branches = new PortalBranchScope(),
	) {
	}//end __construct()

	/**
	 * The one row this subject may see under an id in a collection, or null.
	 *
	 * A foreign, missing, waiting, other-branch or hidden row is null alike, so
	 * the caller answers one 404 and there is no existence oracle.
	 *
	 * @param IRequest                    $request    The request, for the serving portal.
	 * @param array<string, mixed>        $subject    The resolved subject.
	 * @param array<string, mixed>        $collection The subject's own collection.
	 * @param string                      $app        The app whose scope the row is read in.
	 * @param array{register: string, schema: string, id: string} $target The register, schema and id (never trusted).
	 *
	 * @return array<string, mixed>|null The row.
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t03
	 */
	public function row(IRequest $request, array $subject, array $collection, string $app, array $target): ?array {
		// A session with no organisation reads nothing of an organisation-scoped schema
		// (operate-portals-per-organisation REQ-OPO-002).
		if ((string)($subject['organisation'] ?? '') === '' && SchemaTenancy::isOrganisationScoped(schema: $target['schema']) === true) {
			return null;
		}

		$object = $this->reader->readObject(
			register: $target['register'],
			schema: $target['schema'],
			scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $target['id'],
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($collection['scopeClaim'] ?? ''),
			contributingApp: $app,
			via: ($collection['via'] ?? null),
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null),
			filter: (array)($collection['filter'] ?? [])
		);
		if ($object === null || $this->admits(request: $request, subject: $subject, collection: $collection, row: $object) === false) {
			return null;
		}

		return $object;
	}//end row()

	/**
	 * Whether a row the subject's scope already reads passes the list's gates:
	 * its moment has come (`visibleFromField`), it is of the session's branch,
	 * and its case type is not one the serving portal hides.
	 *
	 * @param IRequest             $request    The request, for the serving portal.
	 * @param array<string, mixed> $subject    The resolved subject.
	 * @param array<string, mixed> $collection The collection whose gates apply.
	 * @param array<string, mixed> $row        The row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t03
	 */
	public function admits(IRequest $request, array $subject, array $collection, array $row): bool {
		if ((new VisibleFromGate())->rows(rows: [$row], collection: $collection) === []) {
			return false;
		}

		if ($this->branches->admits(subject: $subject, collection: $collection, row: $row) === false) {
			return false;
		}

		$hidden = ($this->caseTypes?->hiddenForCollection(request: $request, subject: $subject, collection: $collection) ?? []);

		return $this->caseTypes?->rowIsHidden(row: $row, collection: $collection, hidden: $hidden) !== true;
	}//end admits()

	/**
	 * Whether a row the subject already owns through an action (update, a file
	 * field) passes the gates of ANY of the subject's own collections over this
	 * register and schema, honouring `?collection=`. A named collection that is
	 * not one of them is refused. When the request names none and the subject
	 * has no such collection, the action's own declaration stands in, so the
	 * tenant and branch gates still hold. The owned row is the full row the writer re-checks, so every
	 * gate field is on it.
	 *
	 * @param IRequest                                            $request   The request.
	 * @param array<string, mixed>                                $subject   The resolved subject.
	 * @param array<string, mixed>                                $aggregate The subject's aggregated contributions.
	 * @param array{action: array<string, mixed>, app: string}    $match     The authorised action and its app.
	 * @param array{register: string, schema: string, id: string} $target    The register, schema and id.
	 * @param array<string, mixed>                                $row       The row the action's scope owns.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/cases-export-own-data-pdf/tasks.md#t03
	 */
	public function admitsForAction(IRequest $request, array $subject, array $aggregate, array $match, array $target, array $row): bool {
		if ((string)($subject['organisation'] ?? '') === '' && SchemaTenancy::isOrganisationScoped(schema: $target['schema']) === true) {
			return false;
		}

		$candidates = $this->collectionsOver(request: $request, aggregate: $aggregate, target: $target);
		if ($candidates === [] && (string)$request->getParam('collection', '') !== '') {
			// A named collection that is not the subject's own over this schema
			// proves nothing; falling back would skip its waiting and case-type gates.
			return false;
		}

		if ($candidates === []) {
			$candidates[] = ['branchField' => (string)($match['action']['branchField'] ?? '')];
		}

		foreach ($candidates as $collection) {
			if ($this->admits(request: $request, subject: $subject, collection: $collection, row: $row) === true) {
				return true;
			}
		}

		return false;
	}//end admitsForAction()

	/**
	 * The subject's own collections over a register and schema, honouring
	 * `?collection=` when the request names one.
	 *
	 * @param IRequest                                            $request   The request.
	 * @param array<string, mixed>                                $aggregate The subject's aggregated contributions.
	 * @param array{register: string, schema: string, id: string} $target    The register, schema and id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collectionsOver(IRequest $request, array $aggregate, array $target): array {
		$collectionId = (string)$request->getParam('collection', '');
		$found        = [];
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach (($contribution['collections'] ?? []) as $collection) {
				if (($collection['register'] ?? '') === $target['register']
					&& ($collection['schema'] ?? '') === $target['schema']
					&& ($collectionId === '' || ($collection['id'] ?? '') === $collectionId)
				) {
					$found[] = $collection;
				}
			}
		}

		return $found;
	}//end collectionsOver()
}//end class
