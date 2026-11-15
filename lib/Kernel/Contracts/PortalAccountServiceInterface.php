<?php

/**
 * Contract of PortalAccountService
 *
 * The public surface of PortalAccountService that other components call, so they depend
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
 * Contract of PortalAccountService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAccountServiceInterface {

	/**
	 * An account a matching first login has activated.
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * A pending account a clerk withdrew. It never matches a login again.
	 */
	public const STATUS_VOID = 'void';

	/**
	 * The `provisionedBy` of an account a stranger created through self-registration.
	 */
	public const SELF_REGISTRATION = 'self-registration';

	/**
	 * Approve a self-registration that waits for a decision (REQ-ISA-004).
	 *
	 * Only a pending account the registrant created is approved here: a
	 * pending account a clerk provisioned becomes active on its owner's first
	 * sign-in, not by a second clerk's click. `verifiedEmail` stays as the
	 * registrant left it.
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return bool True when the account is now active.
	 *
	 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
	 */
	public function approvePending(string $subjectRef): bool;

	/**
	 * Write an app's claim on an account, server-side (REQ-PIS-003).
	 *
	 * The app id comes from the dispatching context, never from the payload,
	 * so one app can never write under another's name.
	 *
	 * @param string $subjectRef The account to write on.
	 * @param string $appId The dispatching app.
	 * @param string $claimName The claim to set.
	 * @param string $value The claim value.
	 *
	 * @return bool True when the claim landed.
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	public function claim(string $subjectRef, string $appId, string $claimName, string $value): bool;

	/**
	 * The account a `subjectRef` belongs to, or null.
	 *
	 * Used by the `nextcloud` sign-in mode, where the Nextcloud user id IS the
	 * subjectRef. It looks up rather than creates on purpose: minting an
	 * account here would make every user on the instance a citizen of every
	 * portal that enables the mode.
	 *
	 * @param string $subjectRef The subject reference to look up.
	 *
	 * @return array<string, mixed>|null The account, or null when there is none.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	public function findBySubjectRef(string $subjectRef): ?array;

	/**
	 * Find-or-create the `portalAccount` for a validated external identity.
	 *
	 * Looks the account up by `(identityType, identityRef, organisation)`
	 * first — an existing account's OWN `subjectRef` always wins, regardless
	 * of `$subjectRefOverride`, so a stable identity never gets a second,
	 * colliding subjectRef on a later login. Only a genuinely NEW account
	 * uses `$subjectRefOverride` (a validated-claim value) when supplied, or
	 * mints a fresh cryptographically random one otherwise.
	 *
	 * The same holds for the audience: an existing account's OWN stored
	 * audience wins over the `$audience` the sign-in route proposes (a
	 * provider preset or an organisation's claim map). An app that invited
	 * the person as, say, an `employer` wrote that audience on the account;
	 * the eHerkenning preset's `supplier` must not replace it, or the
	 * session would name an audience no account and no provider answers to.
	 * Only a NEW account takes the proposed audience.
	 *
	 * @param string $identityType One of the register's identityType enum.
	 * @param string $identityRef The IdP's pseudonymous identity reference.
	 * @param string $organisation The tenant slug.
	 * @param string $audience The external audience ("supplier"|"client"|...).
	 * @param string|null $subjectRefOverride A validated-claim subjectRef for a
	 *                                        NEW account (null = mint one server-side).
	 * @param string $verifiedEmail An address the broker itself says it
	 *                              verified, used only to claim a pending
	 *                              account (empty = no second pass).
	 *
	 * @return array{subjectRef: string, isNew: bool, audience: string}|null Null when OpenRegister
	 *                                                     is unavailable or the
	 *                                                     write failed (fail closed
	 *                                                     — the caller mints no session).
	 *
	 * @spec openspec/changes/archive/2026-07-24-portal-oidc-broker-login/tasks.md#T08
	 * @spec openspec/specs/supplier-portal/spec.md#the-subject-reference-is-server-derived-never-client-supplied
	 * @spec openspec/specs/portal-identity-space/spec.md
	 * @spec openspec/changes/the-account-names-the-audience-and-the-company/specs/portal-identity-space/spec.md#requirement-an-existing-accounts-audience-wins-over-the-sign-in-routes
	 */
	public function findOrCreate(
		string $identityType,
		string $identityRef,
		string $organisation,
		string $audience,
		?string $subjectRefOverride = null,
		string $verifiedEmail = '',
	): ?array;

	/**
	 * Provision an account before any login (REQ-PIS-001).
	 *
	 * The account is created `pending`: it has no session and is unreachable
	 * from the portal until a first login matches it. A call with neither an
	 * identity reference nor an email is refused, because such a row could
	 * never be matched by anything and would only be a dangling subjectRef.
	 *
	 * @param string $audience The external audience ("client"|"supplier"|...).
	 * @param string $organisation The tenant slug.
	 * @param string $identityType One of the register's identityType enum, or ''.
	 * @param string $identityRef The identity reference, or '' when unknown.
	 * @param string $email A contact address, or '' when none is known.
	 * @param bool $verifiedEmail True when that address was verified out of band.
	 * @param string $provisionedBy The staff user id or app id that asked.
	 * @param string $displayName The name to greet the person by, or ''.
	 *
	 * @return array{subjectRef: string, isNew: bool, status: string}|null Null
	 *         when the call is refused or OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one parameter per
	 * declared field of the provisioned row; an options array would lose the
	 * type safety on the identity boundary.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- `verifiedEmail` is a
	 * field OF the account, not a mode this method runs in. It is carried to
	 * the stored row and nothing ever branches on it: one write, no condition.
	 * Splitting the method in two would give two methods differing in a literal.
	 */
	public function provision(
		string $audience,
		string $organisation,
		string $identityType = '',
		string $identityRef = '',
		string $email = '',
		bool $verifiedEmail = false,
		string $provisionedBy = '',
		string $displayName = '',
	): ?array;

	/**
	 * Withdraw a pending account, with the reason on the row (D6).
	 *
	 * Only a `pending` account can be voided: an active account is somebody's
	 * live session and is suspended, not erased by another name.
	 *
	 * @param string $subjectRef The account to withdraw.
	 * @param string $reason Why it was withdrawn.
	 * @param string $voidedBy The staff user id that withdrew it.
	 *
	 * @return bool True when the account is now void.
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	public function voidPending(string $subjectRef, string $reason, string $voidedBy = ''): bool;
}//end interface
