<?php

/**
 * Portaliq Portal Account Presence
 *
 * Answers one question for another app's staff: does this citizen have a
 * portal account, and what is the portal called ("Mijn Zuiddrecht")? A case
 * handling app shows it as a way to reach the person (dossiq's "Bereikbaar
 * via: Mijn Zuiddrecht, berichtenbox"). Read-only, and it says nothing more
 * than yes or no: no address, no login dates, no identifiers.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/portal-account-presence/specs/portal-identity-space/spec.md#requirement-staff-can-ask-whether-a-citizen-has-a-portal-account
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\PortalNameSetting;
use OCA\Portaliq\Service\PortalObjectReader;
use OCP\IUserSession;

/**
 * Whether a citizen has an active portal account.
 *
 * The permission check runs first: the caller must be a signed-in Nextcloud
 * user who is not a portal citizen. A citizen's own session (their uid is the
 * subjectRef of a portal account) may not ask about somebody else. The app
 * that calls this decides whether its user may see the person at all, as
 * dossiq does by reading the person record under the user's own rights.
 *
 * @spec openspec/changes/portal-account-presence/specs/portal-identity-space/spec.md#requirement-staff-can-ask-whether-a-citizen-has-a-portal-account
 */
class PortalAccountPresence {

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader      Reads the account rows.
	 * @param PortalNameSetting  $portalName  The portal's name.
	 * @param IUserSession       $userSession The caller.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalNameSetting $portalName,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Whether the citizen with this BSN has an active portal account.
	 *
	 * @param string $bsn The citizen's BSN.
	 *
	 * @return array{allowed: bool, hasAccount: bool, portalName: string} `allowed` false when the caller may not ask.
	 *
	 * @spec openspec/changes/portal-account-presence/specs/portal-identity-space/spec.md#requirement-staff-can-ask-whether-a-citizen-has-a-portal-account
	 */
	public function forCitizen(string $bsn): array {
		$lookup = new PortalAccountLookup(reader: $this->reader);
		if ($this->callerMayAsk(lookup: $lookup) === false) {
			return ['allowed' => false, 'hasAccount' => false, 'portalName' => ''];
		}

		return [
			'allowed' => true,
			'hasAccount' => $lookup->activeByCitizenNumber(bsn: $bsn) !== null,
			'portalName' => $this->portalName->name(),
		];
	}//end forCitizen()

	/**
	 * Whether the caller is a signed-in user who is not a portal citizen.
	 *
	 * @param PortalAccountLookup $lookup The account lookup.
	 *
	 * @return bool
	 */
	private function callerMayAsk(PortalAccountLookup $lookup): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $lookup->bySubjectRef(subjectRef: $user->getUID()) === null;
	}//end callerMayAsk()
}//end class
