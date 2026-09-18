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
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
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
