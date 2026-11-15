<?php

/**
 * Contract of WaitingAccountInvitation
 *
 * The public surface of WaitingAccountInvitation that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of WaitingAccountInvitation.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WaitingAccountInvitationInterface {

	/**
	 * The secret was right: the waiting account joined the caller's account.
	 */
	public const CLAIMED = 'claimed';

	/**
	 * The caller offered too many wrong secrets.
	 */
	public const LOCKED = 'locked';

	/**
	 * The caller's own account cannot take over a waiting account: it is not
	 * active, did not sign in through an identity provider, or sits in
	 * another organisation. About the caller, never about the invitation,
	 * and nothing is spent or counted (security review L6).
	 */
	public const CANNOT_RECEIVE = 'cannot_receive';

	/**
	 * The secret is right, but the waiting account carries a claim the
	 * caller's account holds with another value. Nothing is spent, so the
	 * invitation stays with its real holder (security review M1).
	 */
	public const CONFLICT = 'conflict';

	/**
	 * Another request holds the lock of the caller's or the waiting account
	 * past a short wait. Nothing changed; trying again is safe.
	 */
	public const BUSY = 'busy';

	/**
	 * The audience the session's own account holds now, or '' when it has
	 * none or is not in the session's organisation. A redeem can move an
	 * unbound account into the invitation's audience; the caller then
	 * reissues the session so its audience follows the account.
	 *
	 * @param array<string, mixed> $subject The session.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	public function audienceOf(array $subject): string;

	/**
	 * Mint the secret of a waiting account, for the mail.
	 *
	 * Only the app that provisioned the account may ask, and only while the
	 * account is still waiting: pending, with no identity reference of its
	 * own, and with an address to mail. Asking again replaces the earlier
	 * secret, so a lost mail is answered by inviting once more.
	 *
	 * @param string $subjectRef The waiting account.
	 * @param string $appId The app asking, from its own dispatching context.
	 * @param DateTimeImmutable|null $now The moment the secret is dated from.
	 *
	 * @return array{token: string, email: string, organisation: string, expiresAt: string}|null
	 *         Null when the account is not this app's waiting account or the write failed.
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	public function issue(string $subjectRef, string $appId, ?DateTimeImmutable $now = null): ?array;

	/**
	 * Mint the short code of a waiting account, for a paper letter.
	 *
	 * The same secret in a form a person can type. Only the hash is stored,
	 * with the same expiry as a link, and it replaces an earlier link or
	 * code. The code is answered to the app that asked, because somebody has
	 * to print it: this is the one place the secret is seen by staff.
	 *
	 * @param string $subjectRef The waiting account.
	 * @param string $appId The app asking, from its own dispatching context.
	 * @param DateTimeImmutable|null $now The moment the code is dated from.
	 *
	 * @return array{code: string, expiresAt: string}|null Null when the
	 *         account is not this app's waiting account or the write failed.
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	public function issueCode(string $subjectRef, string $appId, ?DateTimeImmutable $now = null): ?array;

	/**
	 * Redeem a secret for the person who is signed in.
	 *
	 * The caller's own account must be active and signed in through an
	 * identity provider. The secret must belong to a waiting account in the
	 * session's organisation that is still pending, has no identity reference
	 * and has not expired. The secret is spent before the join, so it can
	 * never work twice, and a wrong one counts against the caller.
	 *
	 * Two exclusive locks make this one step (security review M2, L1): the
	 * caller's account while the attempts are read and counted, and the
	 * waiting account while it is read again, spent and joined. A second
	 * request with the same secret waits, reads the spent hash and joins
	 * nothing.
	 *
	 * @param array<string, mixed> $subject The session, as PortalSessionService resolves it.
	 * @param string $secret The secret the person hands back.
	 * @param DateTimeImmutable|null $now The moment to judge expiry and the lock against.
	 *
	 * @return string One of CLAIMED, NOT_VALID, LOCKED, CANNOT_RECEIVE, CONFLICT and BUSY.
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	public function redeem(array $subject, #[\SensitiveParameter] string $secret, ?DateTimeImmutable $now = null): string;
}//end interface
