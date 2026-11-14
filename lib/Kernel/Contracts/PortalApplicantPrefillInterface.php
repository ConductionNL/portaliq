<?php

/**
 * Contract of PortalApplicantPrefill
 *
 * The public surface of PortalApplicantPrefill that other components call, so they depend
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
 * Contract of PortalApplicantPrefill.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalApplicantPrefillInterface {

	/**
	 * The prefill for one render.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject, or null.
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 *
	 * @return array<string, mixed> Field name to value. Empty for a visitor
	 *         with no session.
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 */
	public function forSubject(?array $subject, array $fields): array;
}//end interface
