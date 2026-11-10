<?php

/**
 * Portaliq My Cases Run
 *
 * The state of one "My cases" listing while `MyCasesController` builds it:
 * the subject and its aggregate, the case types the serving portal hides,
 * the rows listed so far and the mandates the identity holds. It never
 * leaves the controller that builds it.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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

namespace OCA\Portaliq\Controller;

/**
 * One "My cases" listing in progress.
 *
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
 */
final class MyCasesRun {

	/**
	 * The case types the serving portal hides.
	 *
	 * @var array<int, string>
	 */
	public array $hidden = [];

	/**
	 * The cases listed so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * The mandates the identity holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $held = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $subject   The resolved subject.
	 * @param array<string, mixed> $aggregate The subject's aggregated contributions.
	 */
	public function __construct(
		public readonly array $subject,
		public readonly array $aggregate,
	) {
	}//end __construct()
}//end class
