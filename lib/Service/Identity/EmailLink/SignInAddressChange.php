<?php

/**
 * Portaliq Sign-in Address Change
 *
 * The one way the address an e-mail link goes to changes (security review
 * H2): staff, or a session at `substantial` or higher. An `email-link`
 * session is `low` and is refused, so a stolen link cannot redirect future
 * links to another mailbox. Every change voids the unspent links and tells
 * the old address.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity\EmailLink
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
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#9
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity\EmailLink;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;

/**
 * Changes an account's sign-in address, or refuses.
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#9
 */
class SignInAddressChange {
	/**
	 * The address changed.
	 */
	public const CHANGED = 'changed';

	/**
	 * The session is not trusted enough to change it.
	 */
	public const TRUST_TOO_LOW = 'trust_too_low';

	/**
	 * The new address is malformed, or the account is not an `email` one.
	 */
	public const REFUSED = 'refused';

	/**
	 * The store did not take the write.
	 */
	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriter   $writer  Writes the account.
	 * @param EmailLinkAddress     $address Normalises the address.
	 * @param EmailLinkTokens      $tokens  Voids the unspent links.
	 * @param PortalIdentityMailer $mailer  Tells the old address.
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly EmailLinkAddress $address,
		private readonly EmailLinkTokens $tokens,
		private readonly PortalIdentityMailer $mailer,
	) {
	}//end __construct()

	/**
	 * Change the sign-in address of an `email` account.
	 *
	 * @param array<string, mixed> $account    The account row.
	 * @param string               $newAddress The new address.
	 * @param bool                 $byStaff    Whether staff make the change.
	 * @param string               $trust      The session's trust when the account holder makes it.
	 *
	 * @return string One of the constants.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-an-e-mail-link-session-is-a-fresh-low-session-that-cannot-raise-itself-req-iwi-011
	 */
	public function change(array $account, string $newAddress, bool $byStaff, string $trust = ''): string {
		if ($byStaff === false && PortalSessionService::trustSatisfies(subjectTrust: $trust, minTrust: 'substantial') === false) {
			return self::TRUST_TOO_LOW;
		}

		$normalised = $this->address->normalise(address: $newAddress);
		$id         = (string)($account['uuid'] ?? $account['id'] ?? ((array)($account['@self'] ?? []))['uuid'] ?? '');
		if ($id === ''
			|| ($account['identityType'] ?? '') !== EmailLinkEligibility::IDENTITY_TYPE
			|| filter_var($normalised, FILTER_VALIDATE_EMAIL) === false
		) {
			return self::REFUSED;
		}

		$old          = (string)($account['signInAddress'] ?? '');
		$organisation = (string)($account['organisation'] ?? '');
		$written      = $this->writer->updateObject(
			register: 'portaliq',
			schema: 'portalAccount',
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: ['signInAddress' => $normalised]
		);
		if ($written === null) {
			return self::FAILED;
		}

		$this->tokens->voidFor(subjectRef: (string)($account['subjectRef'] ?? ''), organisation: $organisation);
		if ($old !== '' && $old !== $normalised) {
			$this->mailer->sendSignInAddressChanged(email: $old, organisation: $organisation, moment: new DateTimeImmutable());
		}

		return self::CHANGED;
	}//end change()
}//end class
