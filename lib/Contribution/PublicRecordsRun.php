<?php

/**
 * Portaliq Public Records Run
 *
 * The state of one PublicRecordsNormaliser::normalise() call: the provider
 * the declared lists must name callable methods on, the check for those
 * methods, and the ids kept so far. It never leaves the normaliser.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * One normalise() call of the public record lists.
 *
 * @internal
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t1
 */
final class PublicRecordsRun {
	/**
	 * The ids kept so far.
	 *
	 * @var array<string, bool>
	 */
	public array $seen = [];

	/**
	 * Constructor.
	 *
	 * @param object                 $provider The contributing app's provider.
	 * @param TimelineProviderMethod $methods  The provider method check.
	 */
	public function __construct(
		public readonly object $provider,
		public readonly TimelineProviderMethod $methods,
	) {
	}//end __construct()
}//end class
