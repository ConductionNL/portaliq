<?php

/**
 * Portaliq Case Row Marker
 *
 * What "My cases" needs to know about a case row and about the case
 * collections a subject's contributions declare: whether a row is closed, the
 * date it sorts by, and whether the page (and its "Closed" tab) is there at
 * all (cases-my-cases-page).
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
 * @spec openspec/specs/portal-my-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Marks case rows closed or open and announces the "My cases" page.
 *
 * @spec openspec/specs/portal-my-cases/spec.md
 */
class CaseRowMarker {
	/**
	 * Whether any contribution declares a case collection, and whether any of
	 * those declares a closed marker: what the portal needs to know to show
	 * "My cases" and its "Closed" tab.
	 *
	 * @param array<string, mixed> $aggregate The subject's aggregated manifest.
	 *
	 * @return array{enabled: bool, closedMarker: bool}
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-your-cases-from-every-app-in-one-list-req-cmc-001
	 */
	public function announce(array $aggregate): array {
		$announced = ['enabled' => false, 'closedMarker' => false];
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			foreach (($contribution['collections'] ?? []) as $collection) {
				if (is_array($collection) === false || ($collection['kind'] ?? '') !== PortalCaseListReader::KIND) {
					continue;
				}

				$announced['enabled'] = true;
				if ((string)($collection['closedField'] ?? '') !== '') {
					$announced['closedMarker'] = true;
				}
			}
		}

		return $announced;
	}//end announce()

	/**
	 * A case is closed when the collection's declared closed field holds a
	 * value. A collection that declares none has no closed cases: nothing is
	 * filed away on a guess (REQ-CMC-002).
	 *
	 * @param array<string, mixed> $row The case row.
	 * @param array<string, mixed> $collection The declared case collection.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-open-and-closed-cases-are-told-apart-by-a-declared-field-req-cmc-002
	 */
	public function isClosed(array $row, array $collection): bool {
		$field = (string)($collection['closedField'] ?? '');
		if ($field === '') {
			return false;
		}

		$value = ($row[$field] ?? null);

		return $value !== null && $value !== '' && $value !== [] && $value !== false;
	}//end isClosed()

	/**
	 * Whether a case is over: withdrawn from the portal (`withdrawnAt`), or
	 * closed by the marker its collection declares. The case screen reads
	 * this; "My cases" files the same cases under Closed.
	 *
	 * @param array<string, mixed> $row The case row.
	 * @param string $closedField The collection's `closedField`, or ''.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/citizen-case-ended-shows-only-its-state/specs/citizen-writes-on-their-own-case/spec.md#requirement-a-case-that-has-ended-offers-nothing-and-explains-nothing
	 */
	public function hasEnded(array $row, string $closedField): bool {
		$withdrawnAt = ($row['withdrawnAt'] ?? null);
		if (is_string($withdrawnAt) === true && $withdrawnAt !== '') {
			return true;
		}

		return $this->isClosed(row: $row, collection: ['closedField' => $closedField]);
	}//end hasEnded()

	/**
	 * The date a case sorts by: its own `created` or `startedAt`, else the
	 * record's creation date.
	 *
	 * @param array<string, mixed> $row The case row.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-your-cases-from-every-app-in-one-list-req-cmc-001
	 */
	public function dateOf(array $row): string {
		$own = ($row['created'] ?? $row['startedAt'] ?? null);
		if ($own !== null) {
			return (string)$own;
		}

		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			return '';
		}

		return (string)($self['created'] ?? '');
	}//end dateOf()
}//end class
