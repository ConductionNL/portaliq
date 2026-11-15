<?php

/**
 * Contract of NextcloudAccountProvisioner
 *
 * The public surface of NextcloudAccountProvisioner that other components call, so they depend
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
 * Contract of NextcloudAccountProvisioner.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NextcloudAccountProvisionerInterface {

	/**
	 * Provision, or find, the active account of a Nextcloud user.
	 *
	 * @param string $appId        The dispatching app.
	 * @param string $uid          The Nextcloud user id; becomes the subjectRef.
	 * @param string $portal       The slug of a published portal that offers the `nextcloud` mode.
	 * @param string $audience     The audience the account belongs to.
	 * @param string $organisation The tenant; must be the portal's.
	 * @param string $email        A contact address, or ''.
	 * @param string $displayName  The name to greet the person by, or ''.
	 *
	 * @return array{subjectRef: string, status: string, isNew: bool}|string The account, or a refusal word:
	 *         `refused`, `unknown_user`, `unknown_portal`, `portal_mismatch`, `mode_not_offered`,
	 *         `conflict`, `not_active` or `unavailable`.
	 *
	 * @spec openspec/changes/an-app-provisions-a-nextcloud-account/specs/portal-identity-space/spec.md#requirement-an-app-may-provision-an-active-account-for-a-nextcloud-user
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one parameter per field
	 * of the account asked for, as on the provision event it answers.
	 */
	public function provision(
		string $appId,
		string $uid,
		string $portal,
		string $audience,
		string $organisation,
		string $email = '',
		string $displayName = '',
	): array|string;
}//end interface
