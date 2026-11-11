<?php

/**
 * Portaliq Reference Link Request
 *
 * One request to issue a one-time link for a case: the case type that must
 * admit the reference route, the case number, the address the link goes to,
 * the tenant, and the case collection the link opens.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

/**
 * A reference link someone asks to issue.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
final class ReferenceLinkRequest {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $caseType      The case's type, for its kinds.
	 * @param string               $caseReference The case number.
	 * @param string               $email         The address the link is sent to.
	 * @param string               $organisation  The tenant.
	 * @param string               $caseRegister  The register of the case collection the link opens.
	 * @param string               $caseSchema    The schema of that collection.
	 */
	public function __construct(
		public readonly array $caseType,
		public readonly string $caseReference,
		public readonly string $email,
		public readonly string $organisation,
		public readonly string $caseRegister = '',
		public readonly string $caseSchema = '',
	) {
	}//end __construct()
}//end class
