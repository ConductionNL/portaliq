<?php

/**
 * Portaliq Traffic Event Check.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The state of one posted event while `TrafficEventValidator` checks it: the
 * event, the configuration resolver, the portal's configuration, whether
 * the visitor consented and whether the caller is server-side. It never
 * leaves the validator that builds it.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * One event check in progress.
 *
 * @spec openspec/specs/portal-traffic-experiments/spec.md#requirement-sensitive-measurement-must-be-off-by-default-and-warned-in-the-admin-ui
 */
final class TrafficEventCheck {

	/**
	 * The portal's resolved configuration.
	 *
	 * @var array<string, mixed>
	 */
	public array $config = [];

	/**
	 * Whether the visitor has consented.
	 *
	 * @var bool
	 */
	public bool $hasConsent = false;

	/**
	 * Whether the caller is server-side.
	 *
	 * @var bool
	 */
	public bool $serverSide = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>  $event    The posted event.
	 * @param TrafficConfigResolver $resolver The configuration resolver.
	 */
	public function __construct(
		public readonly array $event,
		public readonly TrafficConfigResolver $resolver,
	) {
	}//end __construct()
}//end class
