<?php

/**
 * Portaliq Notification Normalise Run
 *
 * One pass over a contribution's notifications list: what is kept so far,
 * what was dropped and why, and where the plain keys were placed.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-app-declares-which-change-a-resident-hears-about-req-nap-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * The state of one NotificationRuleNormaliser::normalise() call. Never leaves that class.
 *
 * @internal
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-app-declares-which-change-a-resident-hears-about-req-nap-001
 */
final class NotificationNormaliseRun {
	/**
	 * The entries kept so far, plain keys and shaped rules, in list order.
	 *
	 * @var array<int, string|array<string, mixed>>
	 */
	public array $kept = [];

	/**
	 * Why each dropped entry was dropped, in the order it is reported.
	 *
	 * @var array<int, string>
	 */
	public array $dropped = [];

	/**
	 * The plain keys by their place in $kept, judged once the rules are known.
	 *
	 * @var array<int, string>
	 */
	public array $plainKeys = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $collections The contribution's normalised collections.
	 * @param string                           $appId       The contributing app.
	 */
	public function __construct(
		public readonly array $collections,
		public readonly string $appId,
	) {
	}//end __construct()
}//end class
