<?php

/**
 * Portaliq Session Revoke Run
 *
 * The state of one revoke-all while `PortalSessionRevoker` walks it: the
 * tenant and the acting admin, the one account of a per-account run, the
 * read offset and the counts so far. It never leaves the revoker that
 * builds it.
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
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * One revoke-all in progress.
 *
 * @spec openspec/specs/portal-session-idle-and-sso/spec.md#requirement-signing-out-ends-the-shared-sign-in-req-sis-006
 */
final class SessionRevokeRun {

	/**
	 * One account only, or '' for the whole organisation.
	 *
	 * @var string
	 */
	public string $subjectRef = '';

	/**
	 * Where the next page of live sessions starts.
	 *
	 * @var int
	 */
	public int $offset = 0;

	/**
	 * How many sessions were revoked.
	 *
	 * @var int
	 */
	public int $revoked = 0;

	/**
	 * How many sessions could not be revoked.
	 *
	 * @var int
	 */
	public int $failed = 0;

	/**
	 * Constructor.
	 *
	 * @param string $organisation The tenant to revoke every session for.
	 * @param string $admin        The Nextcloud user id of the acting admin.
	 */
	public function __construct(
		public readonly string $organisation,
		public readonly string $admin,
	) {
	}//end __construct()
}//end class
