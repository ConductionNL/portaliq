<?php

/**
 * Contract of NotificationRuleNormaliser
 *
 * The public surface of NotificationRuleNormaliser that other components call, so they depend
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
 * Contract of NotificationRuleNormaliser.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NotificationRuleNormaliserInterface {

	/**
	 * Whether a collection is scoped through a claim or a join rather than by
	 * the subject reference on the record. The change rule index asks the
	 * same question, so a rule the normaliser would drop is never acted on.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md
	 */
	public function scopedElsewhere(array $collection): bool;

	/**
	 * The shape of a rule key an app sends on a portalMessage it writes: the
	 * app id, a dot, the app's own key. The app is the part before the first
	 * dot (PortalRecordChangeListener::onForeignMessage).
	 *
	 * @var string
	 */
	public const APP_RULE_KEY_PATTERN = '/^([a-z][a-z0-9_-]*)\.[A-Za-z0-9_.-]+$/';
}//end interface
