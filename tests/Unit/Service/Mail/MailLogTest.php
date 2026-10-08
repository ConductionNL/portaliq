<?php

/**
 * Tests for the mail log (mail-templates-admin-screen).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Mail
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
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Mail;

use DateTimeImmutable;
use OCA\Portaliq\BackgroundJob\MailLogRetentionJob;
use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\Mail\MailLog
 * @covers \OCA\Portaliq\BackgroundJob\MailLogRetentionJob
 */
class MailLogTest extends TestCase {
	/**
	 * An address keeps its first letter and its domain, nothing else.
	 *
	 * @return void
	 */
	public function testMask(): void {
		self::assertSame('s***@example.nl', MailLog::mask(email: ' sanne@example.nl '));
		self::assertSame('***', MailLog::mask(email: 'not-an-address'));
		self::assertSame('***', MailLog::mask(email: '@example.nl'));
	}//end testMask()

	/**
	 * A row never holds the full address or any text of the mail.
	 *
	 * @return void
	 */
	public function testRecordStoresMaskedAddressOnly(): void {
		$written = [];
		$writer  = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (...$args) use (&$written): array {
				$written = $args[5];

				return ['id' => '1'];
			}
		);
		$log = new MailLog($this->createMock(PortalObjectReader::class), $writer);

		self::assertTrue($log->record(portal: 'p', templateKey: 'invitation', email: 'Sanne@Example.nl', status: 'delivered', caseRef: 'Z-1'));
		self::assertSame('S***@Example.nl', $written['recipientMasked']);
		self::assertSame(hash('sha256', 'sanne@example.nl'), $written['recipientHash']);
		self::assertStringNotContainsString('Sanne@', json_encode($written));
		self::assertFalse($log->record(portal: 'p', templateKey: 'invitation', email: 'a@b.nl', status: 'sent'));
	}//end testRecordStoresMaskedAddressOnly()

	/**
	 * Only rows older than 90 days go; a row without a date stays.
	 *
	 * @return void
	 */
	public function testPurgeRemovesOnlyExpiredRows(): void {
		$now    = new DateTimeImmutable('2026-10-08T12:00:00+00:00');
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn(
			[
				['id' => 'old', 'sentAt' => '2026-06-01T10:00:00+00:00'],
				['id' => 'new', 'sentAt' => '2026-10-01T10:00:00+00:00'],
				['id' => 'undated'],
			]
		);
		$deleted = [];
		$writer  = $this->createMock(PortalObjectWriter::class);
		$writer->method('deleteObject')->willReturnCallback(
			function (...$args) use (&$deleted): bool {
				$deleted[] = $args[5];

				return true;
			}
		);

		self::assertSame(1, (new MailLog($reader, $writer))->purgeExpired(now: $now));
		self::assertSame(['old'], $deleted);
	}//end testPurgeRemovesOnlyExpiredRows()

	/**
	 * Only a failed row can be sent again, and it queues a row that points back.
	 *
	 * @return void
	 */
	public function testQueueRetry(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturnCallback(
			static function (...$args): ?array {
				return match ($args[4]) {
					'f' => ['status' => 'failed', 'portal' => 'p', 'templateKey' => 'invitation', 'recipientMasked' => 's***@x.nl', 'recipientHash' => 'h'],
					'd' => ['status' => 'delivered'],
					default => null,
				};
			}
		);
		$written = [];
		$writer  = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (...$args) use (&$written): array {
				$written = $args[5];

				return ['id' => '2'];
			}
		);
		$log = new MailLog($reader, $writer);

		self::assertSame('not_found', $log->queueRetry(id: 'zzz'));
		self::assertSame('not_failed', $log->queueRetry(id: 'd'));
		self::assertSame('queued', $log->queueRetry(id: 'f'));
		self::assertSame('f', $written['retryOf']);
		self::assertSame('queued', $written['status']);
	}//end testQueueRetry()

	/**
	 * The job asks the log to purge.
	 *
	 * @return void
	 */
	public function testJobPurges(): void {
		$log = $this->createMock(MailLog::class);
		$log->expects(self::once())->method('purgeExpired');
		$job = new MailLogRetentionJob($this->createMock(ITimeFactory::class), $log);

		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
		self::assertSame(86400, $job->getInterval());
	}//end testJobPurges()
}//end class
