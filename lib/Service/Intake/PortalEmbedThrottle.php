<?php

/**
 * Portaliq Portal Embed Throttle
 *
 * Counts frame renders and submissions per origin and per address inside a
 * rolling window (ADR-082). An embedded form is the most reachable surface the
 * portal has: it is on somebody else's website, it needs no account, and it
 * creates a case. The counter is what keeps that from being a queue anybody
 * can fill.
 *
 * Counting happens before the answer, so a refused submission is itself
 * counted: being refused never buys a fresh budget.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCP\ICacheFactory;
use Throwable;

/**
 * Counts embed traffic per origin and per address.
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */
class PortalEmbedThrottle {
	/**
	 * The cache namespace.
	 */
	private const PREFIX = 'portaliq_embed';

	/**
	 * The rolling window, in seconds.
	 */
	private const WINDOW = 3600;

	/**
	 * Submissions one origin may make in a window.
	 */
	private const PER_ORIGIN = 120;

	/**
	 * Submissions one address may make in a window.
	 */
	private const PER_ADDRESS = 20;

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
	 * Count one submission and say whether it may proceed.
	 *
	 * @param string $origin The framing origin.
	 * @param string $address The visitor's address.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function allow(string $origin, string $address): bool {
		$originCount = $this->bump(key: 'o:' . $origin);
		$addressCount = $this->bump(key: 'a:' . $address);

		// A null count means there is no counter to judge by; the framework's
		// own anonymous rate limit is the floor in that case.
		if ($originCount === null || $addressCount === null) {
			return true;
		}

		return $originCount <= self::PER_ORIGIN && $addressCount <= self::PER_ADDRESS;
	}//end allow()

	/**
	 * Increment one counter inside the window and return its new value.
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
			$cache->set($hashed, $next, self::WINDOW);
			return $next;
		} catch (Throwable $exception) {
			return null;
		}//end try
	}//end bump()
}//end class
