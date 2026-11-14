<?php

/**
 * Contract of PortalCaseAccessGuard
 *
 * The public surface of PortalCaseAccessGuard that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of PortalCaseAccessGuard.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalCaseAccessGuardInterface {

	/**
	 * The ADR-023 action reviewing a change proposal is gated by.
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public const ACTION_REVIEW_PROPOSAL = 'portal.review-proposal';

	/**
	 * Whether this user may perform one gated act on one record.
	 *
	 * @param IUser $user The staff user.
	 * @param string $register The register the record lives in.
	 * @param string $schema The schema the record lives in.
	 * @param string $id The record.
	 * @param string $action The ADR-023 action being gated.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function mayAct(IUser $user, string $register, string $schema, string $id, string $action): bool;

	/**
	 * Whether this user may raise an ask on this case.
	 *
	 * @param IUser $user The staff user.
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/partner-tasks-in-the-portal/spec.md
	 */
	public function mayAsk(IUser $user, string $register, string $schema, string $id): bool;

	/**
	 * Whether the signed-in user may read one record. OpenRegister judges the
	 * read with RBAC and multitenancy on, as the session's user, which is why
	 * no user is passed. Proposing a change needs this and no gated action.
	 *
	 * @param string $register The register the record lives in.
	 * @param string $schema   The schema the record lives in.
	 * @param string $id       The record.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function mayRead(string $register, string $schema, string $id): bool;
}//end interface
