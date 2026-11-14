<?php

/**
 * Contract of EmailLinkSender
 *
 * The public surface of EmailLinkSender that other components call, so they depend
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
 * Contract of EmailLinkSender.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmailLinkSenderInterface {

	/**
	 * Handle one accepted request.
	 *
	 * @param string $portalSlug The portal it was asked on.
	 * @param string $address    The address as typed.
	 * @param string $cookieHash SHA-256 of the requesting browser's cookie.
	 *
	 * @return bool True when a link was mailed (for the tests and the log only).
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-reveals-nothing-about-accounts-req-iwi-007
	 */
	public function handle(string $portalSlug, string $address, string $cookieHash): bool;
}//end interface
