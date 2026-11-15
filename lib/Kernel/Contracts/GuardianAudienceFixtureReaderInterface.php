<?php

/**
 * Contract of GuardianAudienceFixtureReader
 *
 * The public surface of GuardianAudienceFixtureReader that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of GuardianAudienceFixtureReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GuardianAudienceFixtureReaderInterface {

	/**
	 * Every guardian subjectRef whose audience intersects a target (school OR
	 * group OR child, any match) — the count/enumeration primitive the
	 * newsletter preflight, the newsletter send check and the emergency push
	 * all call, so the preflight count can never drift from the actual
	 * delivery set.
	 *
	 * Fixture rows are matched first, and a guardian with a fixture row is
	 * matched on that row only, the same precedence resolveAudience() keeps.
	 * Every other active guardian account is then resolved through the school
	 * app (guardian-enumeration-from-the-school-app).
	 *
	 * @param array{schoolRef?: string, groupRefs?: array<int, string>, childRefs?: array<int, string>} $target The target.
	 *
	 * @return array<int, string> Distinct guardian subjectRefs.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
	 * @spec openspec/changes/archive/2026-10-07-guardian-enumeration-from-the-school-app/tasks.md#T2
	 */
	public function guardiansMatching(array $target): array;
}//end interface
