<?php

/**
 * Portaliq Citizen Write Throttle
 *
 * A portal write surface without a rate limit is a way to fill a
 * municipality's storage from a phone (D7). The framework's `#[AnonRateLimit]`
 * counts by address, which is the wrong unit here: one household behind one
 * address is not an attack, and one identity across a mobile network's
 * addresses is not innocent. So this counts the two units that matter, per
 * identity and per case, on top of the framework's floor rather than instead
 * of it.
 *
 * It fails OPEN when the distributed cache is unavailable, deliberately: the
 * framework limit still stands underneath, and a portal that refuses every
 * citizen because memcache is down refuses the wrong people.
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\ICacheFactory;
use Throwable;

/**
 * Counts citizen writes per identity and per case inside a rolling window.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenWriteThrottle {
	/**
	 * The cache namespace.
	 */
	private const PREFIX = 'portaliq_citizen_write';

	/**
	 * The rolling window, in seconds.
	 */
	private const WINDOW = 3600;

	/**
	 * Writes one identity may make across all of its cases in a window.
	 */
	private const PER_IDENTITY = 60;

	/**
	 * Writes one identity may make on a single case in a window.
	 */
	private const PER_CASE = 20;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Creates the distributed counter store.
	 */
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()

	/**
	 * Count one write and say whether it is still within both limits. Counting
	 * happens before the answer, so a refused write is itself counted: a caller
	 * hammering the surface does not get a free retry budget by being refused.
	 *
	 * @param string $subjectRef The portal identity making the write.
	 * @param string $caseId The case being written on.
	 *
	 * @return bool True when the write may proceed.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function allow(string $subjectRef, string $caseId): bool {
		if ($subjectRef === '') {
			return false;
		}

		$identityCount = $this->bump(key: 'i:' . $subjectRef);
		$caseCount = $this->bump(key: 'c:' . $subjectRef . ':' . $caseId);

		// A null count means there is no counter to judge by; the framework's
		// own limit is the floor in that case.
		if ($identityCount === null || $caseCount === null) {
			return true;
		}

		return $identityCount <= self::PER_IDENTITY && $caseCount <= self::PER_CASE;
	}//end allow()

	/**
	 * Increment one counter inside the window and return its new value, or
	 * null when there is no usable cache.
	 *
	 * @param string $key The counter key.
	 *
	 * @return int|null
	 */
	private function bump(string $key): ?int {
		try {
			$cache = $this->cacheFactory->createDistributed(self::PREFIX);
			$hashed = hash('sha256', $key);
			$current = $cache->get($hashed);
			if (is_int($current) === false) {
				$cache->set($hashed, 1, self::WINDOW);
				return 1;
			}

			$next = ($current + 1);
			// Re-setting keeps the window rolling from the FIRST write, not the
			// last: `set` with the same ttl on an existing key restarts it in
			// some backends, which would let a steady stream never expire. The
			// ttl is therefore the remaining budget's lifetime, deliberately
			// approximate, and the exactness is not what protects the storage.
			$cache->set($hashed, $next, self::WINDOW);
			return $next;
		} catch (Throwable $e) {
			return null;
		}//end try
	}//end bump()
}//end class
