<?php

/**
 * Portaliq Contact Invitation Link Test
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
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\ContactInvitationLink;
use OCA\Portaliq\Service\Identity\ContactRows;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;

/**
 * Following an invitation link, over an in-memory store that honours the
 * scope field of every read and write.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
 */
class ContactInvitationLinkTest extends TestCase {

	private const NOW = '2026-10-08T10:00:00+00:00';

	/**
	 * Rows by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Whether the writer refuses updates.
	 *
	 * @var bool
	 */
	private bool $writeFails = false;

	private int $seq = 0;

	protected function setUp(): void {
		$this->rows = [
			'inv' => [
				'id' => 'inv', 'owner' => 'a', 'organisation' => 'org-1', 'role' => 'contact', 'state' => 'invited',
				'email' => 'new@example.nl', 'tokenHash' => hash('sha256', 'secret'), 'expiresAt' => '2026-10-22T10:00:00+00:00',
			],
		];
		$this->writeFails = false;
	}//end setUp()

	/**
	 * The link, wired to the in-memory store.
	 *
	 * @return ContactInvitationLink
	 */
	private function link(): ContactInvitationLink {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			fn (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation='', int $limit=200): array => array_values(
				array_filter($this->rows, static fn (array $r): bool => ($r[$scopeField] ?? null) === $subjectRef && ($r['organisation'] ?? '') === $organisation)
			)
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
				if ($this->writeFails === true || isset($this->rows[$id]) === false || $this->rows[$id][$scopeField] !== $subjectRef) {
					return null;
				}

				return ($this->rows[$id] = ($data + $this->rows[$id]));
			}
		);
		$accounts = $this->createMock(PortalAccountLookup::class);
		$accounts->method('bySubjectRef')->willReturnCallback(static fn (string $ref): ?array => ['subjectRef' => $ref, 'displayName' => 'Anna']);

		return new ContactInvitationLink($reader, new ContactRows(reader: $reader), $writer, $accounts);
	}//end link()

	/**
	 * The rows `$owner` holds in the approved state, by the other side.
	 *
	 * @param string $owner The holder.
	 *
	 * @return array<string, string> Name by other side.
	 */
	private function approvedOf(string $owner): array {
		$out = [];
		foreach ($this->rows as $row) {
			if ($row['owner'] === $owner && $row['state'] === 'approved') {
				$out[(string)$row['contactRef']] = (string)$row['displayName'];
			}
		}

		return $out;
	}//end approvedOf()

	public function testFollowingTheLinkSignedInJoinsBothSidesOnce(): void {
		$link = $this->link();
		$now  = new DateTimeImmutable(self::NOW);

		$this->assertSame('not_found', $link->accept(subject: ['subjectRef' => 'a', 'organisation' => 'org-1'], token: 'secret', displayName: 'A', now: $now), 'the inviter');
		$this->assertSame('not_found', $link->accept(subject: ['subjectRef' => 'n', 'organisation' => ''], token: 'secret', displayName: 'N', now: $now));
		$this->assertSame('sent', $link->accept(subject: ['subjectRef' => 'n', 'organisation' => 'org-1'], token: 'secret', displayName: 'Nina', now: $now));
		$this->assertSame(['n' => 'Nina'], $this->approvedOf('a'));
		$this->assertSame(['a' => 'Anna'], $this->approvedOf('n'));
		$this->assertSame('', $this->rows['inv']['tokenHash']);
		$this->assertSame('not_found', $link->accept(subject: ['subjectRef' => 'm', 'organisation' => 'org-1'], token: 'secret', displayName: 'M', now: $now), 'used');
	}//end testFollowingTheLinkSignedInJoinsBothSidesOnce()

	public function testAnInvitationHeldForARegistrationJoinsOnActivation(): void {
		$link = $this->link();
		$now  = new DateTimeImmutable(self::NOW);

		$this->assertTrue($link->holdForRegistration(token: 'secret', newRef: 'n', organisation: 'org-1', now: $now));
		$this->assertSame('n', $this->rows['inv']['contactRef']);
		$this->assertSame('invited', $this->rows['inv']['state'], 'held, not joined');
		$this->assertSame(0, $link->joinOnActivation(account: ['subjectRef' => 'x', 'organisation' => 'org-1'], now: $now), 'another account');
		$this->assertSame(0, $link->joinOnActivation(account: ['subjectRef' => 'n', 'organisation' => 'org-2'], now: $now), 'another tenant');

		$this->assertSame(1, $link->joinOnActivation(account: ['subjectRef' => 'n', 'organisation' => 'org-1', 'displayName' => 'Nina'], now: $now));
		$this->assertSame(['n' => 'Nina'], $this->approvedOf('a'));
		$this->assertSame(['a' => 'Anna'], $this->approvedOf('n'));
	}//end testAnInvitationHeldForARegistrationJoinsOnActivation()

	public function testRefusalsAndAFailedWrite(): void {
		$link = $this->link();
		$now  = new DateTimeImmutable(self::NOW);

		$this->assertFalse($link->holdForRegistration(token: '', newRef: 'n', organisation: 'org-1', now: $now));
		$this->assertFalse($link->holdForRegistration(token: 'secret', newRef: 'n', organisation: 'org-1', now: $now->modify('+15 days')), 'expired');
		$this->assertSame('', $link->invitationEmail(token: 'secret', organisation: 'org-1', now: $now->modify('+15 days')), 'expired');
		$this->assertSame('', $link->invitationEmail(token: 'secret', organisation: 'org-2', now: $now), 'another tenant');
		$this->assertSame('new@example.nl', $link->invitationEmail(token: 'secret', organisation: 'org-1', now: $now));

		$this->writeFails = true;
		$this->assertFalse($link->holdForRegistration(token: 'secret', newRef: 'n', organisation: 'org-1', now: $now));
		$this->assertSame('failed', $link->accept(subject: ['subjectRef' => 'n', 'organisation' => 'org-1'], token: 'secret', displayName: 'Nina', now: $now));
		$this->assertSame([], $this->approvedOf('n'), 'no half link');
	}//end testRefusalsAndAFailedWrite()
}//end class
