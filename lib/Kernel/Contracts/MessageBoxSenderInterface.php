<?php

/**
 * Contract of MessageBoxSender
 *
 * The public surface of MessageBoxSender that other components call, so they depend
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
 * Contract of MessageBoxSender.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MessageBoxSenderInterface {

	/**
	 * Who portaliq says asked for the letter; integriq's status reports carry it back.
	 *
	 * @var string
	 */
	public const REQUESTED_BY = 'portaliq';

	/**
	 * Send one message box job and record the attempt.
	 *
	 * @param array<string, mixed> $argument The job argument MessageBoxChannel queued.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function send(array $argument): void;
}//end interface
