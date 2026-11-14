<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Kernel\Contracts\PortalObjectReaderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalPdfExportInterface;
use OCA\Portaliq\Kernel\Contracts\SubmissionReceiptServiceInterface;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\Notifications\PortalNoticeLanguage;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A receipt's PDF holds its reference, its moment of receipt and the answers
 * of its data copy, and nothing else of the message; it opens only for the
 * subject the receipt belongs to, and someone else's receipt renders nothing.
 *
 * @spec openspec/changes/capability-rows-without-a-requirement/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
 */
class SubmissionReceiptPdfTest extends TestCase {

	/**
	 * The resident's receipt as OpenRegister stores it.
	 *
	 * @var array<string, mixed>
	 */
	private const RECEIPT = [
		'id' => 'msg-1',
		'subjectRef' => 'anna',
		'subject' => 'Ontvangstbevestiging',
		'body' => 'Wij hebben uw verzoek ontvangen.',
		'referenceId' => 'WMEBV-2026-0193',
		'receivedAt' => 'not a moment',
		'read' => true,
		'dataCopy' => ['onderwerp' => 'Fietspad Dorpsstraat', 'spoed' => true, 'periodes' => ['2025', '2026']],
	];

	/**
	 * The service over a reader, a renderer and translations that echo the key.
	 *
	 * @param PortalObjectReaderInterface|null $reader The reader.
	 * @param PortalPdfExportInterface|null $pdf The renderer.
	 *
	 * @return SubmissionReceiptService
	 */
	private function service(?PortalObjectReaderInterface $reader, ?PortalPdfExportInterface $pdf): SubmissionReceiptService {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (): IL10N {
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));
				$l10n->method('l')->willReturn(false);

				return $l10n;
			}
		);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolveByOrganisation')->willReturn(null);

		return new SubmissionReceiptService(
			$this->createMock(PortalObjectWriter::class),
			new PortalNoticeLanguage($factory, $portals),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(NotificationDispatchService::class),
			null,
			$reader,
			$pdf
		);
	}//end service()

	/**
	 * A reader that returns the given row for any read.
	 *
	 * @param array<string, mixed>|null $row The row.
	 *
	 * @return PortalObjectReaderInterface
	 */
	private function reader(?array $row): PortalObjectReaderInterface {
		$reader = $this->createMock(PortalObjectReaderInterface::class);
		$reader->method('readObject')->willReturn($row);

		return $reader;
	}//end reader()

	/**
	 * The resident's own receipt renders with its reference, moment and answers only.
	 *
	 * @return void
	 */
	public function testTheOwnReceiptRendersItsReferenceMomentAndAnswers(): void {
		$reader = $this->createMock(PortalObjectReaderInterface::class);
		$reader->expects($this->once())->method('readObject')->with(
			'portaliq',
			'portalMessage',
			'subjectRef',
			'anna',
			'msg-1',
			'org-1',
			'',
			'',
			null,
			'resident'
		)->willReturn(self::RECEIPT);
		$rendered = [];
		$pdf      = $this->createMock(PortalPdfExportInterface::class);
		$pdf->method('available')->willReturn(true);
		$pdf->expects($this->once())->method('render')->willReturnCallback(
			static function (string $title, array $columns, array $rows) use (&$rendered): string {
				$rendered = compact('title', 'columns', 'rows');
				return '%PDF-1.7 receipt';
			}
		);

		$result = $this->service(reader: $reader, pdf: $pdf)->receiptPdf(
			subject: ['subjectRef' => 'anna', 'organisation' => 'org-1', 'audience' => 'resident'],
			id: 'msg-1'
		);

		$this->assertSame(['status' => SubmissionReceiptServiceInterface::RECEIPT_PDF, 'bytes' => '%PDF-1.7 receipt', 'name' => 'Confirmation of receipt, reference WMEBV-2026-0193'], $result);
		$this->assertSame('Confirmation of receipt, reference WMEBV-2026-0193', $rendered['title']);
		$this->assertSame([['key' => 'part', 'label' => 'Part'], ['key' => 'content', 'label' => 'Content']], $rendered['columns']);
		$this->assertSame(
			[
				['part' => 'Reference', 'content' => 'WMEBV-2026-0193'],
				['part' => 'Received', 'content' => 'not a moment'],
				['part' => 'onderwerp', 'content' => 'Fietspad Dorpsstraat'],
				['part' => 'spoed', 'content' => 'true'],
				['part' => 'periodes', 'content' => '2025, 2026'],
			],
			$rendered['rows']
		);
		$this->assertStringNotContainsString('Wij hebben', json_encode($rendered, JSON_THROW_ON_ERROR));
	}//end testTheOwnReceiptRendersItsReferenceMomentAndAnswers()

	/**
	 * Another resident's request reads nothing back and renders nothing.
	 *
	 * @return void
	 */
	public function testAnotherResidentGetsNotFoundAndNothingRenders(): void {
		$pdf = $this->createMock(PortalPdfExportInterface::class);
		$pdf->expects($this->never())->method('render');
		$pdf->expects($this->never())->method('available');

		$scopedOut = $this->service(reader: $this->reader(row: null), pdf: $pdf)->receiptPdf(subject: ['subjectRef' => 'bram'], id: 'msg-1');
		$otherRow  = $this->service(reader: $this->reader(row: self::RECEIPT), pdf: $pdf)->receiptPdf(subject: ['subjectRef' => 'bram'], id: 'msg-1');

		$this->assertSame(['status' => SubmissionReceiptServiceInterface::RECEIPT_NOT_FOUND], $scopedOut);
		$this->assertSame(['status' => SubmissionReceiptServiceInterface::RECEIPT_NOT_FOUND], $otherRow);
	}//end testAnotherResidentGetsNotFoundAndNothingRenders()

	/**
	 * A message without a reference or a data copy is no receipt, and an empty id or subject reads nothing.
	 *
	 * @return void
	 */
	public function testAMessageThatIsNoReceiptIsNotFound(): void {
		$notice = self::RECEIPT;
		unset($notice['dataCopy']);
		$unreferenced                = self::RECEIPT;
		$unreferenced['referenceId'] = '';
		$never = $this->createMock(PortalObjectReaderInterface::class);
		$never->expects($this->never())->method('readObject');

		$this->assertSame('not_found', $this->service(reader: $this->reader(row: $notice), pdf: null)->receiptPdf(subject: ['subjectRef' => 'anna'], id: 'msg-1')['status']);
		$this->assertSame('not_found', $this->service(reader: $this->reader(row: $unreferenced), pdf: null)->receiptPdf(subject: ['subjectRef' => 'anna'], id: 'msg-1')['status']);
		$this->assertSame('not_found', $this->service(reader: $never, pdf: null)->receiptPdf(subject: ['subjectRef' => 'anna'], id: '')['status']);
		$this->assertSame('not_found', $this->service(reader: $never, pdf: null)->receiptPdf(subject: [], id: 'msg-1')['status']);
	}//end testAMessageThatIsNoReceiptIsNotFound()

	/**
	 * A reader that throws answers not found rather than failing open or loud.
	 *
	 * @return void
	 */
	public function testAReadThatThrowsIsNotFound(): void {
		$reader = $this->createMock(PortalObjectReaderInterface::class);
		$reader->method('readObject')->willThrowException(new RuntimeException('OpenRegister is down'));

		$this->assertSame(['status' => 'not_found'], $this->service(reader: $reader, pdf: null)->receiptPdf(subject: ['subjectRef' => 'anna'], id: 'msg-1'));
	}//end testAReadThatThrowsIsNotFound()

	/**
	 * Without a renderer the answer is unavailable; a failed or refused render is failed.
	 *
	 * @return void
	 */
	public function testARendererThatCannotOrFailsSaysSo(): void {
		$off = $this->createMock(PortalPdfExportInterface::class);
		$off->method('available')->willReturn(false);
		$off->expects($this->never())->method('render');
		$broken = $this->createMock(PortalPdfExportInterface::class);
		$broken->method('available')->willReturn(true);
		$broken->method('render')->willReturn(null);
		$tooLarge = $this->createMock(PortalPdfExportInterface::class);
		$tooLarge->method('available')->willReturn(true);
		$tooLarge->method('render')->willReturn(PortalPdfExportInterface::TOO_LARGE);
		$subject = ['subjectRef' => 'anna'];

		$this->assertSame(['status' => 'pdf_unavailable'], $this->service(reader: $this->reader(row: self::RECEIPT), pdf: null)->receiptPdf(subject: $subject, id: 'msg-1'));
		$this->assertSame(['status' => 'pdf_unavailable'], $this->service(reader: $this->reader(row: self::RECEIPT), pdf: $off)->receiptPdf(subject: $subject, id: 'msg-1'));
		$this->assertSame(['status' => 'pdf_failed'], $this->service(reader: $this->reader(row: self::RECEIPT), pdf: $broken)->receiptPdf(subject: $subject, id: 'msg-1'));
		$this->assertSame(['status' => 'pdf_failed'], $this->service(reader: $this->reader(row: self::RECEIPT), pdf: $tooLarge)->receiptPdf(subject: $subject, id: 'msg-1'));
	}//end testARendererThatCannotOrFailsSaysSo()
}//end class
