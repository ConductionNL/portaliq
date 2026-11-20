<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\Notifications\PortalNoticeLanguage;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * submission-row-actions 1.2: registering a failed receipt again writes the
 * receipt from the stored record and marks only the record's own receipt
 * fields; a retry that fails leaves the record failed.
 *
 * @covers \OCA\Portaliq\Service\SubmissionReceiptService
 * @uses   \OCA\Portaliq\Service\ReceiptRun
 * @uses   \OCA\Portaliq\Service\Notifications\PortalNoticeLanguage
 *
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-register-a-failed-receipt-again
 */
class SubmissionReceiptRegisterAgainTest extends TestCase {

	/**
	 * The mocked subject-scoped writer.
	 *
	 * @var PortalObjectWriter&MockObject
	 */
	private PortalObjectWriter $writer;

	/**
	 * The mocked dispatcher, which a retry must not call.
	 *
	 * @var NotificationDispatchService&MockObject
	 */
	private NotificationDispatchService $dispatch;


	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->writer   = $this->createMock(PortalObjectWriter::class);
		$this->dispatch = $this->createMock(NotificationDispatchService::class);
	}//end setUp()


	/**
	 * The service, with a clock at 2023-11-14T22:13:20+00:00.
	 *
	 * @return SubmissionReceiptService
	 */
	private function service(): SubmissionReceiptService {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function () {
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []) => vsprintf($text, $parameters));
				return $l10n;
			}
		);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolveByOrganisation')->willReturn(null);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1700000000);

		return new SubmissionReceiptService($this->writer, new PortalNoticeLanguage($factory, $portals), $clock, $this->createMock(LoggerInterface::class), $this->dispatch);
	}//end service()


	/**
	 * A stored failed submission.
	 *
	 * @return array<string, mixed>
	 */
	private static function failedRecord(): array {
		return [
			'@self' => ['id' => 'sub-1'],
			'subjectRef' => 'bsn-1',
			'organisation' => 'gemeente',
			'appId' => 'pipelinq',
			'actionId' => 'bulky-waste',
			'payloadCopy' => ['address' => 'Lindelaan 1'],
			'receiptMessageRef' => 'WMEBV-0011223344556677',
			'submittedAt' => '2026-11-02T09:41:00+00:00',
			'deliveryStatus' => 'failed',
		];
	}//end failedRecord()


	/**
	 * The receipt is written from the record's own data and keeps its
	 * reference; the record is marked delivered and nothing else of it is written.
	 *
	 * @return void
	 */
	public function testRegisterAgainWritesTheReceiptAndMarksTheRowDelivered(): void {
		$this->writer->expects($this->once())
			->method('createObject')
			->with(
				'portaliq',
				'portalMessage',
				'subjectRef',
				'bsn-1',
				'gemeente',
				$this->callback(static function (array $data): bool {
					return $data['referenceId'] === 'WMEBV-0011223344556677'
						&& $data['dataCopy'] === ['address' => 'Lindelaan 1']
						&& $data['read'] === false
						&& $data['receivedAt'] === '2023-11-14T22:13:20+00:00'
						&& str_contains($data['subject'], 'WMEBV-0011223344556677');
				})
			)
			->willReturn(['id' => 'msg-1']);
		$this->writer->expects($this->once())
			->method('updateObject')
			->with('portaliq', 'portalSubmission', 'subjectRef', 'bsn-1', 'gemeente', 'sub-1', ['receiptMessageRef' => 'WMEBV-0011223344556677', 'deliveryStatus' => 'delivered'])
			->willReturn(['id' => 'sub-1']);
		$this->dispatch->expects($this->never())->method('dispatch');

		$this->assertTrue($this->service()->registerAgain(record: self::failedRecord()));
	}//end testRegisterAgainWritesTheReceiptAndMarksTheRowDelivered()


	/**
	 * The receipt body names when the resident submitted, not now.
	 *
	 * @return void
	 */
	public function testTheReceiptNamesTheOriginalSubmissionMoment(): void {
		$body = '';
		$this->writer->method('createObject')->willReturnCallback(
			static function (...$args) use (&$body) {
				$body = $args[5]['body'];
				return ['id' => 'msg-1'];
			}
		);
		$this->writer->method('updateObject')->willReturn(['id' => 'sub-1']);

		$this->service()->registerAgain(record: self::failedRecord());

		$this->assertStringContainsString('2026', $body);
		$this->assertStringNotContainsString('2023', $body);
	}//end testTheReceiptNamesTheOriginalSubmissionMoment()


	/**
	 * A fallback record without a reference gets a new one, used on both writes.
	 *
	 * @return void
	 */
	public function testARecordWithoutAReferenceGetsANewOne(): void {
		$record = self::failedRecord();
		unset($record['receiptMessageRef'], $record['payloadCopy']);
		$written = '';
		$this->writer->method('createObject')->willReturnCallback(
			static function (...$args) use (&$written) {
				$written = $args[5]['referenceId'];
				return ['id' => 'msg-1'];
			}
		);
		$this->writer->expects($this->once())
			->method('updateObject')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), 'sub-1', $this->callback(static fn (array $values): bool => $values['receiptMessageRef'] !== '' && $values['deliveryStatus'] === 'delivered'))
			->willReturnCallback(
				function (...$args) use (&$written) {
					$this->assertSame($written, $args[6]['receiptMessageRef']);
					return ['id' => 'sub-1'];
				}
			);

		$this->assertTrue($this->service()->registerAgain(record: $record));
		$this->assertMatchesRegularExpression('/^WMEBV-[0-9A-F]{16}$/', $written);
	}//end testARecordWithoutAReferenceGetsANewOne()


	/**
	 * A receipt write that fails leaves the record failed.
	 *
	 * @return void
	 */
	public function testAFailedRetryKeepsTheRowFailed(): void {
		$this->writer->method('createObject')->willReturn(null);
		$this->writer->expects($this->once())
			->method('updateObject')
			->with('portaliq', 'portalSubmission', 'subjectRef', 'bsn-1', 'gemeente', 'sub-1', ['deliveryStatus' => 'failed'])
			->willReturn(['id' => 'sub-1']);

		$this->assertFalse($this->service()->registerAgain(record: self::failedRecord()));
	}//end testAFailedRetryKeepsTheRowFailed()


	/**
	 * A receipt write that throws is caught and leaves the record failed.
	 *
	 * @return void
	 */
	public function testAThrowingRetryIsCaughtAndKeepsTheRowFailed(): void {
		$this->writer->method('createObject')->willThrowException(new RuntimeException('down'));
		$this->writer->expects($this->once())
			->method('updateObject')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), 'sub-1', ['deliveryStatus' => 'failed'])
			->willReturn(['id' => 'sub-1']);

		$this->assertFalse($this->service()->registerAgain(record: self::failedRecord()));
	}//end testAThrowingRetryIsCaughtAndKeepsTheRowFailed()


	/**
	 * When the record cannot be marked delivered, the retry does not report success.
	 *
	 * @return void
	 */
	public function testAnUnmarkedRecordIsNotASuccess(): void {
		$this->writer->method('createObject')->willReturn(['id' => 'msg-1']);
		$this->writer->method('updateObject')->willReturn(null);

		$this->assertFalse($this->service()->registerAgain(record: self::failedRecord()));
	}//end testAnUnmarkedRecordIsNotASuccess()


	/**
	 * A record without an id or a subject writes nothing.
	 *
	 * @return void
	 */
	public function testARecordWithoutAnIdOrSubjectWritesNothing(): void {
		$this->writer->expects($this->never())->method('createObject');
		$this->writer->expects($this->never())->method('updateObject');

		$noId = self::failedRecord();
		unset($noId['@self']);
		$noSubject = self::failedRecord();
		$noSubject['subjectRef'] = '';

		$this->assertFalse($this->service()->registerAgain(record: $noId));
		$this->assertFalse($this->service()->registerAgain(record: $noSubject));
	}//end testARecordWithoutAnIdOrSubjectWritesNothing()
}//end class
