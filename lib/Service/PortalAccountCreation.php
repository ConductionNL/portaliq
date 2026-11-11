<?php

/**
 * Portaliq Portal Account Creation
 *
 * Creates `portalAccount` rows: it mints a new account's subjectRef once,
 * server-side, with a cryptographically secure generator, writes the row,
 * and provisions a pending account before any login (REQ-PIS-001). The
 * subjectRef is NEVER accepted from a request parameter (design.md,
 * contract-v2 IDOR discipline).
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\AccountStatus;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCP\Security\ISecureRandom;

/**
 * Creates portal accounts: a sign-in's new account and a provisioned one.
 *
 * @spec openspec/specs/portal-identity-space/spec.md#requirement-an-account-can-be-provisioned-before-any-login-req-pis-001
 */
class PortalAccountCreation {
	/**
	 * The OpenRegister register the `portalAccount` schema lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The OpenRegister schema recording linked external identities.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * The read half of the account space, for the account a provisioning
	 * would duplicate.
	 *
	 * @var PortalAccountLookup
	 */
	private readonly PortalAccountLookup $lookup;

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface $reader Looks up an existing account.
	 * @param PortalObjectWriter $writer Creates the account row.
	 * @param ISecureRandom $random Mints a NEW account's subjectRef.
	 */
	public function __construct(
		ScopedCollectionReaderInterface $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
	) {
		$this->lookup = new PortalAccountLookup(reader: $reader);
	}//end __construct()

	/**
	 * Provision an account before any login (REQ-PIS-001).
	 *
	 * The account is created `pending`: it has no session and is unreachable
	 * from the portal until a first login matches it. A call with neither an
	 * identity reference nor an email is refused, because such a row could
	 * never be matched by anything and would only be a dangling subjectRef.
	 *
	 * @param AccountProvisionRequest $request The account to provision.
	 *
	 * @return array{subjectRef: string, isNew: bool, status: string}|null Null
	 *         when the call is refused or OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	public function provision(AccountProvisionRequest $request): ?array {
		return $this->provisionAccount(run: $this->runFor(request: $request));
	}//end provision()

	/**
	 * The provisioning a request starts: its audience and tenant, then its
	 * identity, address, name and who asked.
	 *
	 * @param AccountProvisionRequest $request The account to provision.
	 *
	 * @return AccountProvisionRun
	 */
	private function runFor(AccountProvisionRequest $request): AccountProvisionRun {
		$run = new AccountProvisionRun(audience: $request->audience, organisation: $request->organisation);
		$run->identityType  = $request->identityType;
		$run->identityRef   = $request->identityRef;
		$run->email         = $request->email;
		$run->verifiedEmail = $request->verifiedEmail;
		$run->provisionedBy = $request->provisionedBy;
		$run->displayName   = $request->displayName;
		return $run;
	}//end runFor()

	/**
	 * Provision the account the run describes: refused without an audience
	 * and tenant, or with neither an identity nor an address; the account
	 * already provisioned for it when there is one; else a new pending one.
	 *
	 * @param AccountProvisionRun $run The provisioning.
	 *
	 * @return array{subjectRef: string, isNew: bool, status: string}|null
	 */
	private function provisionAccount(AccountProvisionRun $run): ?array {
		if ($run->isProvisionable() === false) {
			return null;
		}

		$existing = $this->accountAlreadyProvisioned(run: $run);
		if ($existing !== null) {
			return [
				'subjectRef' => (string)($existing['subjectRef'] ?? ''),
				'isNew' => false,
				'status' => (string)($existing['status'] ?? AccountStatus::ACTIVE),
			];
		}

		return $this->newPendingAccount(run: $run, subjectRef: $this->mintSubjectRef());
	}//end provisionAccount()

	/**
	 * Create the pending account under a freshly minted subjectRef; null when
	 * none was minted or the write fails.
	 *
	 * @param AccountProvisionRun $run        The provisioning.
	 * @param string              $subjectRef The minted subjectRef.
	 *
	 * @return array{subjectRef: string, isNew: bool, status: string}|null
	 */
	private function newPendingAccount(AccountProvisionRun $run, string $subjectRef): ?array {
		if ($subjectRef === '') {
			return null;
		}

		$created = $this->createRow(organisation: $run->organisation, data: $this->pendingAccountRow(run: $run, subjectRef: $subjectRef));
		if ($created === null) {
			return null;
		}

		return ['subjectRef' => $subjectRef, 'isNew' => true, 'status' => AccountStatus::PENDING];
	}//end newPendingAccount()

	/**
	 * The row of a provisioned account: its identity when it has one, then
	 * pending, with who asked and when.
	 *
	 * @param AccountProvisionRun $run        The provisioning.
	 * @param string              $subjectRef The minted subjectRef.
	 *
	 * @return array<string, mixed>
	 */
	private function pendingAccountRow(AccountProvisionRun $run, string $subjectRef): array {
		return $run->identityFields() + [
			'audience' => $run->audience,
			'subjectRef' => $subjectRef,
			'organisation' => $run->organisation,
			'displayName' => $run->displayName,
			'email' => $run->email,
			'verifiedEmail' => $run->verifiedEmail,
			'status' => AccountStatus::PENDING,
			'provisionedBy' => $run->provisionedBy,
			'provisionedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];
	}//end pendingAccountRow()

	/**
	 * Write a new account row in the tenant.
	 *
	 * @param string               $organisation The tenant slug.
	 * @param array<string, mixed> $data         The row.
	 *
	 * @return array<string, mixed>|null The written row, or null when the write failed.
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#the-subject-reference-is-server-derived-never-client-supplied
	 */
	public function createRow(string $organisation, array $data): ?array {
		return $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			data: $data
		);
	}//end createRow()

	/**
	 * Mint a fresh, cryptographically random subjectRef for a brand-new account.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#the-subject-reference-is-server-derived-never-client-supplied
	 */
	public function mintSubjectRef(): string {
		return $this->random->generate(32, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
	}//end mintSubjectRef()

	/**
	 * The account this provision call would duplicate, or null.
	 *
	 * An identity reference is matched on that reference. A call with no
	 * identity reference falls back to a pending, email-only account, which
	 * is the only kind an address alone may claim.
	 *
	 * @param AccountProvisionRun $run The provisioning.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	private function accountAlreadyProvisioned(AccountProvisionRun $run): ?array {
		if ($run->hasIdentity() === true) {
			return $this->lookup->byIdentity(identityType: $run->identityType, identityRef: $run->identityRef, organisation: $run->organisation);
		}

		return $this->lookup->pendingByVerifiedEmail(email: $run->email, organisation: $run->organisation);
	}//end accountAlreadyProvisioned()
}//end class
