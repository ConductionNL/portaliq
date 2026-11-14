<?php

/**
 * Contract of PortalIntakeConfirmation
 *
 * The public surface of PortalIntakeConfirmation that other components call, so they depend
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
 * Contract of PortalIntakeConfirmation.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalIntakeConfirmationInterface {

	/**
	 * Mail the confirmation when the form asks for it. The mailer logs the
	 * outcome; there is no portal record to write it on any more (decision 179).
	 *
	 * @param array<string, mixed> $site      The portal.
	 * @param array<string, mixed> $render    What the binding renders to.
	 * @param array<string, mixed> $answers   The accepted answers.
	 * @param string               $reference The reference of the object the request created.
	 * @param array<string, mixed> $moments   The destination's confirmation properties (receivedAt, termStartsAt, deadline).
	 *
	 * @return string The address the mail went to, or '' when none went.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#2.4
	 */
	public function send(array $site, array $render, array $answers, string $reference, array $moments = []): string;
}//end interface
