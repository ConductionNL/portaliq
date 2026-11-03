<?php

/**
 * Portaliq PortalClaimScopeEvent
 *
 * Dispatched before an account's app claim scopes a read or a write. The app
 * that owns the claim may refuse it for the account's organisation, for
 * instance when the claim names a record of another tenant; a refused claim
 * is treated as absent, so the read returns nothing and no write happens.
 * An event nobody answers leaves the claim as it is.
 *
 * @category Event
 * @package  OCA\Portaliq\Event
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
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * Asks the app that owns a claim whether it may scope for this organisation.
 *
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
 */
class PortalClaimScopeEvent extends Event {

	/**
	 * Whether a listener refused the claim.
	 *
	 * @var boolean
	 */
	private bool $refused = false;

	/**
	 * Constructor.
	 *
	 * @param array{appId: string, claimName: string} $claim        The claim's app and name.
	 * @param array{value: string, organisation: string} $holder    The claim value and the account's portal organisation slug.
	 */
	public function __construct(
		private readonly array $claim,
		private readonly array $holder,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app whose claim it is.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
	 */
	public function getAppId(): string {
		return $this->claim['appId'];
	}//end getAppId()

	/**
	 * The claim name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
	 */
	public function getClaimName(): string {
		return $this->claim['claimName'];
	}//end getClaimName()

	/**
	 * The value the account holds.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
	 */
	public function getValue(): string {
		return $this->holder['value'];
	}//end getValue()

	/**
	 * The account's portal organisation slug, '' when it names none.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
	 */
	public function getOrganisation(): string {
		return $this->holder['organisation'];
	}//end getOrganisation()

	/**
	 * Refuse the claim for this organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
	 */
	public function refuse(): void {
		$this->refused = true;
	}//end refuse()

	/**
	 * Whether a listener refused the claim.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
	 */
	public function isRefused(): bool {
		return $this->refused;
	}//end isRefused()
}//end class
