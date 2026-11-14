<?php

/**
 * Portaliq Session Trust
 *
 * The eIDAS-aligned trust ranking of the session edge: normalises a subject's
 * trust value and decides whether it meets an entry's declared minimum. Split
 * out of PortalSessionService, whose callers here only rank trust.
 *
 * @category Service
 * @package  OCA\Portaliq\Kernel\Support
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
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Support;

use OCA\Portaliq\Kernel\Contracts\SessionTrustInterface;

/**
 * Ranks trust levels `low < substantial < high`, fail-closed both ways.
 *
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
 */
final class SessionTrust implements SessionTrustInterface {
	/**
	 * The eIDAS-aligned trust vocabulary, ordered ascending (contract v2, A3).
	 * Any subject trust outside this map normalises to `low`; any entry
	 * `minTrust` outside it is unsatisfiable (fail-closed both ways).
	 */
	private const TRUST_ORDER = [
		'low' => 1,
		'substantial' => 2,
		'high' => 3,
	];

	/**
	 * Normalise a subject trust value to the eIDAS-aligned vocabulary.
	 *
	 * Any value outside `low|substantial|high` — including empty, legacy `dev`
	 * or `EH3` — normalises to `low` (fail-closed; ADR-005). This is the single
	 * normalisation point used by the session edge, the registry, and the
	 * contribution controller.
	 *
	 * @param mixed $trust The raw trust value (session claim or config).
	 *
	 * @return string One of `low`, `substantial`, `high`.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
	 */
	public function normaliseTrust(mixed $trust): string {
		if (is_string($trust) === true && isset(self::TRUST_ORDER[$trust]) === true) {
			return $trust;
		}

		return 'low';
	}//end normaliseTrust()

	/**
	 * Whether a subject trust level satisfies an entry's `minTrust`.
	 *
	 * Ordering is `low < substantial < high`. A missing `minTrust` (null or
	 * empty string) defaults to `low`; an unrecognised `minTrust` value makes
	 * the entry unsatisfiable for EVERY subject — a typo must never widen
	 * access (fail-closed; ADR-005). The subject side is normalised first, so
	 * unknown subject trust is compared as `low`.
	 *
	 * @param mixed $subjectTrust The subject's trust value (normalised here).
	 * @param mixed $minTrust The entry's declared minimum, or null/'' when absent.
	 *
	 * @return bool True when the subject meets the threshold.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
	 */
	public function trustSatisfies(mixed $subjectTrust, mixed $minTrust): bool {
		if ($minTrust === null || $minTrust === '') {
			$minTrust = 'low';
		}

		if (is_string($minTrust) === false || isset(self::TRUST_ORDER[$minTrust]) === false) {
			// Unrecognised minTrust → unsatisfiable for everyone.
			return false;
		}

		return self::TRUST_ORDER[$this->normaliseTrust(trust: $subjectTrust)] >= self::TRUST_ORDER[$minTrust];
	}//end trustSatisfies()
}//end class
