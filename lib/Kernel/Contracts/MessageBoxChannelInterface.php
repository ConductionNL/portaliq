<?php

/**
 * Contract of MessageBoxChannel
 *
 * The public surface of MessageBoxChannel that other components call, so they depend
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
 * Contract of MessageBoxChannel.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MessageBoxChannelInterface {

	/**
	 * The channel's name in the notification log and the job argument.
	 *
	 * @var string
	 */
	public const CHANNEL = 'messageBox';

	/**
	 * Queue the send for one new message, when it may go.
	 *
	 * @param array<string, mixed>  $account  The resident's account.
	 * @param array<string, string> $inbox    The inbox entry: app, collection, label, register, schema, scopeField, recipientProvider.
	 * @param string                $recordId The new message's id.
	 *
	 * @return bool Whether a send was queued.
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function enqueue(array $account, array $inbox, string $recordId): bool;
}//end interface
