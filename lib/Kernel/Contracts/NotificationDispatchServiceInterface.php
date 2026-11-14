<?php

/**
 * Contract of NotificationDispatchService
 *
 * The public surface of NotificationDispatchService that other components call, so they depend
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
 * Contract of NotificationDispatchService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NotificationDispatchServiceInterface {

	/**
	 * Rule key for a `portalMessage` create (SubmissionReceiptService's WMEBV
	 * receipt, or any other message-create path).
	 */
	public const RULE_MESSAGE_CREATED = 'message.created';

	/**
	 * Rule key for a successful status-transition update
	 * (`ContributionController::update()`).
	 */
	public const RULE_STATUS_CHANGED = 'status.changed';

	/**
	 * Match a trigger against the contributing app's manifest and enqueue the
	 * dispatch job on a hit. Never throws — a notification side-effect can
	 * never turn an already-successful domain action into a failed one.
	 *
	 * @param string $ruleKey The trigger's rule key (RULE_MESSAGE_CREATED
	 *                        or RULE_STATUS_CHANGED).
	 * @param string $appId The contributing app whose manifest is checked.
	 * @param array<string, mixed> $subject The resolved subject (subjectRef, organisation,
	 *                                      audience — audience is REQUIRED to resolve the
	 *                                      app's manifest via the registry; trust is optional).
	 * @param array<string, string> $record The record the trigger is about ({app, collection, id,
	 *                                      label}), or [] for a trigger about no record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
	 * @spec openspec/specs/supplier-portal/spec.md#dispatch-is-decoupled-from-the-request-path
	 */
	public function dispatch(string $ruleKey, string $appId, array $subject, array $record = []): void;
}//end interface
