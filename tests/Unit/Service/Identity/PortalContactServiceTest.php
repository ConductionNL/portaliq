<?php

/**
 * Portaliq Portal Contact Service Test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Identity
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
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalContactService;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The contact rules, over an in-memory store that honours the owner scope.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */
class PortalContactServiceTest extends TestCase {

	/**
	 * Rows by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Accounts the e-mail lookup finds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $accounts = [];

	/**
	 * Mails the mailer was asked to send.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = [];

	/**
	 * Whether the mailer fails.
	 *
	 * @var bool
	 */
	private bool $mailFails = false;

	private int $seq = 0;

	/**
	 * The service, wired to the in-memory store.
	 *
	 * @return PortalContactService
	 */
	private function service(): PortalContactService {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation='', int $limit=200): array {
				if ($schema === 'portalAccount') {
					return array_values(array_filter($this->accounts, static fn (array $a): bool => ($a['email'] ?? '') === $subjectRef));
				}

				return array_values(array_filter($this->rows, static fn (array $r): bool => ($r[$scopeField] ?? null) === $subjectRef && ($r['organisation'] ?? '') === $organisation));
			}
		);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array {
				$id = 'r' . (++$this->seq);
				return ($this->rows[$id] = ($data + ['id' => $id, $scopeField => $subjectRef, 'organisation' => $organisation]));
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, $data): ?array {
				if (isset($this->rows[$id]) === false || $this->rows[$id][$scopeField] !== $subjectRef) {
					return null;
				}

				return ($this->rows[$id] = ($data + $this->rows[$id]));
			}
		);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('secret-token');
		$mailer = $this->createMock(PortalIdentityMailer::class);
		$mailer->method('send')->willReturnCallback(
			function (string $template, string $email, string $secret, string $organisation, ?array $portal=null, array $details=[]): bool {
				$this->mails[] = ['template' => $template, 'email' => $email, 'secret' => $secret, 'details' => $details];
				return ($this->mailFails === false);
			}
		);
		$lookup = $this->createMock(PortalAccountLookup::class);
		$lookup->method('bySubjectRef')->willReturnCallback(static fn (string $ref): ?array => ['subjectRef' => $ref, 'displayName' => 'Name of ' . $ref]);

		return new PortalContactService($reader, $writer, $random, $mailer, $lookup);
	}//end service()

	/**
	 * A session subject.
	 *
	 * @param string $ref The subject reference.
	 *
	 * @return array<string, mixed>
	 */
	private function subject(string $ref='a'): array {
		return ['subjectRef' => $ref, 'organisation' => 'org-1', 'displayName' => 'Resident ' . $ref];
	}//end subject()

	/**
	 * An address with no account gets a mailed link, and only its hash is stored.
	 *
	 * @return void
	 */
	public function testAnAddressWithoutAnAccountGetsAMailedLinkAndOnlyItsHashIsStored(): void {
		$result = $this->service()->invite(subject: $this->subject(), email: ' New@Example.nl ', message: 'Join me');
		$this->assertSame('sent', $result);
		$this->assertSame('secret-token', $this->mails[0]['secret']);
		$this->assertSame('Resident a', $this->mails[0]['details']['inviter']);
		$row = array_values($this->rows)[0];
		$this->assertSame(hash('sha256', 'secret-token'), $row['tokenHash']);
		$this->assertSame('invited', $row['state']);
		$this->assertSame('new@example.nl', $row['email']);
		$this->assertStringNotContainsString('secret-token', json_encode($this->rows));
	}//end testAnAddressWithoutAnAccountGetsAMailedLinkAndOnlyItsHashIsStored()

	/**
	 * An address with an account gets an approval request on both sides and no mail.
	 *
	 * @return void
	 */
	public function testAnExistingAccountGetsARequestOnBothSidesAndNoMail(): void {
		$this->accounts[] = ['email' => 'b@example.nl', 'status' => 'active', 'subjectRef' => 'b', 'displayName' => 'Bea'];
		$this->assertSame('sent', $this->service()->invite(subject: $this->subject(), email: 'b@example.nl', message: ''));
		$this->assertSame([], $this->mails);
		$states = array_column($this->rows, 'state', 'owner');
		$this->assertSame(['a' => 'invited', 'b' => 'requested'], $states);
	}//end testAnExistingAccountGetsARequestOnBothSidesAndNoMail()

	/**
	 * Bad addresses, your own address, the daily limit and a second open invitation are refused.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$service = $this->service();
		$this->assertSame('invalid', $service->invite(subject: $this->subject(), email: 'nope', message: ''));
		$this->assertSame('invalid', $service->invite(subject: $this->subject(), email: 'x@example.nl', message: str_repeat('x', 501)));
		$this->accounts[] = ['email' => 'me@example.nl', 'status' => 'active', 'subjectRef' => 'a'];
		$this->assertSame('invalid', $service->invite(subject: $this->subject(), email: 'me@example.nl', message: ''));

		$now = new DateTimeImmutable('2026-10-08T10:00:00+00:00');
		$this->assertSame('sent', $service->invite(subject: $this->subject(), email: 'one@example.nl', message: '', now: $now));
		$this->assertSame('duplicate', $service->invite(subject: $this->subject(), email: 'one@example.nl', message: '', now: $now));

		for ($i = 2; $i <= PortalContactService::DAILY_LIMIT; $i++) {
			$this->assertSame('sent', $service->invite(subject: $this->subject(), email: 'p' . $i . '@example.nl', message: '', now: $now));
		}

		$this->assertSame('limit', $service->invite(subject: $this->subject(), email: 'over@example.nl', message: '', now: $now));
		$later = $now->modify('+1 day');
		$this->assertSame('sent', $service->invite(subject: $this->subject(), email: 'over@example.nl', message: '', now: $later));
	}//end testRefusals()

	/**
	 * A mail that did not leave says so and leaves the invitation to send again.
	 *
	 * @return void
	 */
	public function testAMailThatFailedLeavesTheInvitationToResend(): void {
		$this->mailFails = true;
		$service         = $this->service();
		$this->assertSame('failed', $service->invite(subject: $this->subject(), email: 'x@example.nl', message: ''));
		$id = array_key_first($this->rows);
		$this->mailFails = false;
		$this->assertSame('sent', $service->resend(subject: $this->subject(), id: $id));
		$this->assertCount(1, $this->rows);
	}//end testAMailThatFailedLeavesTheInvitationToResend()

	/**
	 * Approving sets both rows approved; declining reads as not accepted for the requester only.
	 *
	 * @return void
	 */
	public function testApprovalAndDecline(): void {
		$this->accounts[] = ['email' => 'b@example.nl', 'status' => 'active', 'subjectRef' => 'b', 'displayName' => 'Bea'];
		$service          = $this->service();
		$service->invite(subject: $this->subject(), email: 'b@example.nl', message: '');
		$request = array_key_last(array_filter($this->rows, static fn (array $r): bool => $r['owner'] === 'b'));
		$this->assertSame('not_found', $service->respond(subject: $this->subject('c'), id: $request, accept: true), 'not your row');
		$this->assertSame('sent', $service->respond(subject: $this->subject('b'), id: $request, accept: true));
		$this->assertSame(['approved', 'approved'], array_values(array_column($this->rows, 'state')));
		$this->assertSame(['a'], $service->approvedRefs(owner: 'b', organisation: 'org-1'));

		$this->rows = [];
		$service->invite(subject: $this->subject(), email: 'b@example.nl', message: '');
		$request = array_key_last(array_filter($this->rows, static fn (array $r): bool => $r['owner'] === 'b'));
		$service->respond(subject: $this->subject('b'), id: $request, accept: false);
		$overview = $service->overview(owner: 'a', organisation: 'org-1');
		$this->assertSame('declined', $overview['outgoing'][0]['state']);
		$this->assertSame([], $service->overview(owner: 'b', organisation: 'org-1')['incoming']);
	}//end testApprovalAndDecline()

	/**
	 * Removing an approved contact ends both rows; a withdrawn invitation ends both.
	 *
	 * @return void
	 */
	public function testRemoveAndWithdrawEndBothSides(): void {
		$this->accounts[] = ['email' => 'b@example.nl', 'status' => 'active', 'subjectRef' => 'b'];
		$service          = $this->service();
		$service->invite(subject: $this->subject(), email: 'b@example.nl', message: '');
		$mine = array_key_first(array_filter($this->rows, static fn (array $r): bool => $r['owner'] === 'a'));
		$this->assertSame('sent', $service->withdraw(subject: $this->subject(), id: $mine));
		$this->assertSame(['withdrawn', 'withdrawn'], array_values(array_column($this->rows, 'state')));
		$this->assertSame('not_found', $service->withdraw(subject: $this->subject(), id: $mine), 'once');

		$this->rows = [];
		$service->invite(subject: $this->subject(), email: 'b@example.nl', message: '');
		$theirs = array_key_first(array_filter($this->rows, static fn (array $r): bool => $r['owner'] === 'b'));
		$service->respond(subject: $this->subject('b'), id: $theirs, accept: true);
		$mine = array_key_first(array_filter($this->rows, static fn (array $r): bool => $r['owner'] === 'a'));
		$this->assertSame('sent', $service->remove(subject: $this->subject(), id: $mine));
		$this->assertSame([], $service->approvedRefs(owner: 'b', organisation: 'org-1'));
	}//end testRemoveAndWithdrawEndBothSides()

	/**
	 * A new account that follows the link becomes a contact on both sides; a used, expired or own link does not.
	 *
	 * @return void
	 */
	public function testFollowingTheLinkApprovesBothSidesOnce(): void {
		$service = $this->service();
		$now     = new DateTimeImmutable('2026-10-08T10:00:00+00:00');
		$service->invite(subject: $this->subject(), email: 'new@example.nl', message: '', now: $now);
		$this->assertSame('not_found', $service->acceptInvitation(subject: $this->subject('a'), token: 'secret-token', displayName: 'Me', now: $now), 'your own link');
		$this->assertSame('not_found', $service->acceptInvitation(subject: $this->subject('n'), token: 'wrong', displayName: 'N', now: $now));
		$this->assertSame('not_found', $service->acceptInvitation(subject: $this->subject('n'), token: 'secret-token', displayName: 'N', now: $now->modify('+15 days')), 'expired');
		$this->assertSame('sent', $service->acceptInvitation(subject: $this->subject('n'), token: 'secret-token', displayName: 'Nina', now: $now->modify('+2 days')));
		$this->assertSame(['n'], $service->approvedRefs(owner: 'a', organisation: 'org-1'));
		$this->assertSame(['a'], $service->approvedRefs(owner: 'n', organisation: 'org-1'));
		$this->assertSame('not_found', $service->acceptInvitation(subject: $this->subject('m'), token: 'secret-token', displayName: 'M', now: $now), 'used');
	}//end testFollowingTheLinkApprovesBothSidesOnce()

	/**
	 * A resident reads only their own rows, and the browser never gets the token hash.
	 *
	 * @return void
	 */
	public function testAnotherSubjectReadsNothingAndNoHashLeaves(): void {
		$service = $this->service();
		$service->invite(subject: $this->subject(), email: 'new@example.nl', message: '');
		$other = $service->overview(owner: 'z', organisation: 'org-1');
		$this->assertSame([], $other['outgoing']);
		$this->assertSame([], $service->overview(owner: 'a', organisation: 'org-2')['outgoing'], 'another tenant');
		$own = $service->overview(owner: 'a', organisation: 'org-1');
		$this->assertStringNotContainsString('tokenHash', json_encode($own));
		$this->assertStringNotContainsString(hash('sha256', 'secret-token'), json_encode($own));
		$this->assertSame('new@example.nl', $own['outgoing'][0]['email']);
	}//end testAnotherSubjectReadsNothingAndNoHashLeaves()
}//end class
