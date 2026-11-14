<?php

/**
 * Contract of PushDeliveryService
 *
 * The public surface of PushDeliveryService that other components call, so they depend
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
 * Contract of PushDeliveryService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PushDeliveryServiceInterface {

	/**
	 * Deliver a push, deferring a non-emergency send during quiet hours.
	 *
	 * @param string $subjectRef The recipient's own subjectRef.
	 * @param string $title The notification title.
	 * @param string $body The notification body.
	 * @param bool $emergency Bypasses quiet hours unconditionally when true (noodmelding).
	 *
	 * @return bool True when sent immediately OR successfully queued; false on failure.
	 *
	 * @spec openspec/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- `emergency` is the
	 * declared shape of the ONE deliberate fail-open branch in this app
	 * (design.md "Emergency bypass"), not an incidental mode switch; naming
	 * it out as two methods would hide that both share the identical
	 * fail-closed default for everything except this one flag.
	 */
	public function deliver(string $subjectRef, string $title, string $body, bool $emergency = false): bool;
}//end interface
