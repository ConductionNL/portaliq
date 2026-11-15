<?php

/**
 * Unit tests for EmailLinkTokens: the state of a link, and voiding.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity\EmailLink;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\ClaimLock;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\Lock\ILockingProvider;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The state of a stored e-mail link at a moment, and which links staff void.
 */
class EmailLinkStatesTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * The tokens over the given reader and writer.
	 *
	 * @param PortalObjectReader|null $reader The reader; the fake store by default.
	 * @param PortalObjectWriter|null $writer The writer; the fake store by default.
	 *
	 * @return EmailLinkTokens
	 */
	private function tokens(?PortalObjectReader $reader=null, ?PortalObjectWriter $writer=null): EmailLinkTokens {
		return new EmailLinkTokens(
			($reader ?? $this->fakeReader()),
			($writer ?? $this->fakeWriter()),
			$this->createMock(ISecureRandom::class),
			new ClaimLock($this->createMock(ILockingProvider::class), 0)
		);
	}//end tokens()

	/**
	 * A sent link is live before its expiry only; a used one says used; any
	 * other state, an expiry that does not parse, or no row is not valid.
	 *
	 * @return void
	 */
	public function testTheStateOfALinkAtAMoment(): void {
		$moment = new DateTimeImmutable('2026-10-09T09:15:00+00:00');
		$cases  = [
			't-live' => [['state' => 'sent', 'expiresAt' => '2026-10-09T09:15:01+00:00'], 'live'],
			't-edge' => [['state' => 'sent', 'expiresAt' => '2026-10-09T09:15:00+00:00'], EmailLinkTokens::NOT_VALID],
			't-used' => [['state' => 'used', 'expiresAt' => '2026-10-09T09:00:00+00:00'], EmailLinkTokens::USED],
			't-void' => [['state' => 'void', 'expiresAt' => '2026-10-09T10:00:00+00:00'], EmailLinkTokens::NOT_VALID],
			't-none' => [['expiresAt' => '2026-10-09T10:00:00+00:00'], EmailLinkTokens::NOT_VALID],
			't-odd'  => [['state' => 'sent', 'expiresAt' => 'never'], EmailLinkTokens::NOT_VALID],
		];
		foreach ($cases as $token => [$row]) {
			$this->seedRow('portalEmailLink', $row + ['tokenHash' => hash('sha256', $token)]);
		}

		$tokens = $this->tokens();
		foreach ($cases as $token => [, $state]) {
			$this->assertSame($state, $tokens->peek(token: $token, now: $moment)['state'], $token);
		}

		$this->assertSame(EmailLinkTokens::NOT_VALID, $tokens->peek(token: 'unknown', now: $moment)['state']);
	}//end testTheStateOfALinkAtAMoment()

	/**
	 * Only the account's own sent links with an id are voided, and only a
	 * write that lands counts; without an account nothing is read.
	 *
	 * @return void
	 */
	public function testWhichLinksAreVoided(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->once())->method('readCollection')->willReturn([
			['uuid' => 'a', 'subjectRef' => 'email:tom', 'state' => 'sent'],
			['uuid' => 'b', 'subjectRef' => 'email:anna', 'state' => 'sent'],
			['uuid' => 'c', 'subjectRef' => 'email:tom', 'state' => 'used'],
			['subjectRef' => 'email:tom', 'state' => 'sent'],
			['uuid' => 'd', 'subjectRef' => 'email:tom', 'state' => 'sent'],
		]);
		$written = [];
		$writer  = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$written): ?array {
				$written[] = [$id, $data];
				if ($id === 'd') {
					return null;
				}

				return $data;
			}
		);

		$tokens = $this->tokens(reader: $reader, writer: $writer);
		$this->assertSame(0, $tokens->voidFor(subjectRef: '', organisation: 'academie'));
		$this->assertSame(1, $tokens->voidFor(subjectRef: 'email:tom', organisation: 'academie'));
		$this->assertSame([['a', ['state' => 'void']], ['d', ['state' => 'void']]], $written);
	}//end testWhichLinksAreVoided()
}//end class
