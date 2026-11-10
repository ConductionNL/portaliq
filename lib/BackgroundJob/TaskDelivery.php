<?php

/**
 * Portaliq Task Delivery
 *
 * One ledger row of PortalTaskDeliveryJob, read once: whose it is, which
 * kind and channel, and the engine's rendered payload.
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
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
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

/**
 * A pending task delivery with a usable party and a known kind.
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
 */
final class TaskDelivery {
	/**
	 * Constructor.
	 *
	 * @param string               $uuid       The ledger row uuid.
	 * @param string               $subjectRef The party's subject reference.
	 * @param string               $kind       `ask`, `re-ask`, `reminder` or `overdue`.
	 * @param string               $channel    `portal-inbox` or `mail`, as recorded.
	 * @param array<string, mixed> $message    The engine's rendered payload.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $subjectRef,
		public readonly string $kind,
		public readonly string $channel,
		public readonly array $message,
	) {
	}//end __construct()
}//end class
