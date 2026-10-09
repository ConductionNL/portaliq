<?php

/**
 * Portaliq Unbound Account
 *
 * Whether a person's own portal account is still unbound: made by their own
 * sign-in, given no audience by an app or a clerk, and holding no claims. Such
 * an account may take on the audience of an invitation whose secret its
 * session hands in (invitation-joins-an-unbound-account).
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
 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\PortalSessionService;

/**
 * The account side of taking on an invitation's audience.
 *
 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies,
 * the one comparison of trust levels, as in ContributionController.
 */
final class UnboundAccount {
	/**
	 * The identity types that name one natural person.
	 */
	private const PERSON_IDENTITIES = ['digid', 'eidas'];

	/**
	 * The audience of a company account. A person's account never moves
	 * into it or out of it.
	 */
	public const BUSINESS_AUDIENCE = 'supplier';

	/**
	 * The trust a session needs before its account takes on an audience.
	 */
	private const ADOPT_TRUST = 'substantial';

	/**
	 * Constructor.
	 *
	 * @param mixed              $trust     The redeeming session's trust level.
	 * @param array<int, string> $audiences The audiences an unbound account of
	 *                                      this organisation may take on
	 *                                      (security review L1; see AudienceMove).
	 */
	public function __construct(
		private readonly mixed $trust,
		private readonly array $audiences,
	) {
	}//end __construct()

	/**
	 * Whether the account may take on the audience of the waiting account:
	 * that audience is one the organisation allows (security review L1), the
	 * account names one natural person (DigiD or eIDAS) with an identity
	 * reference, nobody provisioned it, it holds no claims, it is no company
	 * account, and the session is at substantial or higher.
	 *
	 * What the join itself asks (pending, organisation, conflicting claims)
	 * is WaitingAccountJoin's check, not this one.
	 *
	 * @param array<string, mixed> $account The account that would receive the claims.
	 * @param array<string, mixed> $waiting The account that would be withdrawn.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	public function mayTakeOn(array $account, array $waiting): bool {
		return in_array((string)($waiting['audience'] ?? ''), $this->audiences, true) === true
			&& (string)($account['audience'] ?? '') !== self::BUSINESS_AUDIENCE
			&& in_array((string)($account['identityType'] ?? ''), self::PERSON_IDENTITIES, true) === true
			&& (string)($account['identityRef'] ?? '') !== ''
			&& (string)($account['provisionedBy'] ?? '') === ''
			&& $this->holdsNoClaims(account: $account) === true
			&& PortalSessionService::trustSatisfies(subjectTrust: $this->trust, minTrust: self::ADOPT_TRUST) === true;
	}//end mayTakeOn()

	/**
	 * Whether an account holds no claim of any app.
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return bool
	 */
	private function holdsNoClaims(array $account): bool {
		$held = array_filter((array)($account['claims'] ?? []), static fn (mixed $appClaims): bool => in_array($appClaims, [[], null, ''], true) === false);

		return $held === [];
	}//end holdsNoClaims()
}//end class
