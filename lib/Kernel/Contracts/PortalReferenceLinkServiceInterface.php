<?php

/**
 * Contract of PortalReferenceLinkService
 *
 * The public surface of PortalReferenceLinkService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of PortalReferenceLinkService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalReferenceLinkServiceInterface {

	/**
	 * Whether a case type admits the reference route.
	 *
	 * An absent or empty declaration means `account` only: the closed answer,
	 * because a case type that never considered the question has not agreed
	 * to hand its cases out on a case number.
	 *
	 * @param array<string, mixed> $caseType The case type row.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function admitsReference(array $caseType): bool;

	/**
	 * Issue a one-time link for a case, or refuse.
	 *
	 * @param array<string, mixed> $caseType The case's type, for its kinds.
	 * @param string $caseReference The case number.
	 * @param string $email The address the link is sent to.
	 * @param string $organisation The tenant.
	 * @param string $caseRegister The register of the case collection the
	 *                             link opens (identity-ways-in-screens D2).
	 * @param string $caseSchema The schema of that collection.
	 *
	 * @return array{token: string, expiresAt: string}|null Null when the case
	 *         type does not admit the reference route, or the call is empty.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- each field is stored
	 * on the link row; the reference session reads the case through them.
	 */
	public function issue(
		array $caseType,
		string $caseReference,
		string $email,
		string $organisation,
		string $caseRegister = '',
		string $caseSchema = '',
	): ?array;

	/**
	 * The expiry a link issued now would carry. The answer to a request that
	 * issues nothing carries it too, so the two answers cannot be told apart.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 */
	public function nominalExpiry(): string;

	/**
	 * Follow a link once.
	 *
	 * @param string $token The secret from the mail.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return array{caseReference: string, organisation: string, register: string, schema: string, linkId: string}|null
	 *         Null when the link admits nobody: unknown, already used or
	 *         expired. `linkId` is for the session the link opens, never for
	 *         the answer.
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function redeem(string $token, ?DateTimeImmutable $now = null): ?array;
}//end interface
