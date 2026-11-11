<?php

/**
 * Portaliq Case List Run
 *
 * The state of one case listing while `PortalCaseListReader` reads it: the
 * subject, the case types the serving portal hides and, for a mandated
 * listing, the live mandates. It never leaves the reader that builds it.
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
 * One case listing in progress.
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
 */
final class CaseListRun {

	/**
	 * The live mandates of a mandated listing.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $mandates = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<int, string>   $hidden  The case type ids the serving portal does not show.
	 */
	public function __construct(
		public readonly array $subject,
		public readonly array $hidden,
	) {
	}//end __construct()
}//end class
