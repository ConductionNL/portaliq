<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Controller\SubmissionReceiptController;
use OCA\Portaliq\Http\PdfDownloadResponse;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Kernel\Contracts\SubmissionReceiptServiceInterface;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The receipt PDF route answers only a signed-in subject, asks the service
 * with that subject, and maps its answer onto the file or a status.
 *
 * @spec openspec/changes/capability-rows-without-a-requirement/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
 */
class SubmissionReceiptControllerTest extends TestCase {

	/**
	 * The controller over a session that resolves the given subject.
	 *
	 * @param array<string, mixed>|null $subject The subject behind the bearer.
	 * @param SubmissionReceiptServiceInterface $receipts The receipts.
	 *
	 * @return SubmissionReceiptController
	 */
	private function controller(?array $subject, SubmissionReceiptServiceInterface $receipts): SubmissionReceiptController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization' ? 'Bearer t' : ''));
		$session = $this->createMock(PortalSessionServiceInterface::class);
		$session->method('resolveFromBearer')->with('Bearer t')->willReturn($subject);

		return new SubmissionReceiptController($request, $session, $receipts);
	}//end controller()

	/**
	 * Without a session the route answers 401 and asks nothing.
	 *
	 * @return void
	 */
	public function testWithoutASessionNothingIsAsked(): void {
		$receipts = $this->createMock(SubmissionReceiptServiceInterface::class);
		$receipts->expects($this->never())->method('receiptPdf');
		$controller = $this->controller(subject: null, receipts: $receipts);

		$this->assertInstanceOf(PortalProtected::class, $controller);
		$this->assertSame(401, $controller->pdf('msg-1')->getStatus());
	}//end testWithoutASessionNothingIsAsked()

	/**
	 * The subject's own receipt downloads as the rendered PDF.
	 *
	 * @return void
	 */
	public function testTheOwnReceiptDownloads(): void {
		$subject  = ['subjectRef' => 'anna', 'organisation' => 'org-1'];
		$receipts = $this->createMock(SubmissionReceiptServiceInterface::class);
		$receipts->expects($this->once())->method('receiptPdf')->with($subject, 'msg-1')
			->willReturn(['status' => SubmissionReceiptServiceInterface::RECEIPT_PDF, 'bytes' => '%PDF-1.7 x', 'name' => 'Ontvangst WMEBV-2026-0193']);

		$response = $this->controller(subject: $subject, receipts: $receipts)->pdf('msg-1');

		$this->assertInstanceOf(PdfDownloadResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('%PDF-1.7 x', $response->render());
		$this->assertSame('application/pdf', $response->getHeaders()['Content-Type']);
	}//end testTheOwnReceiptDownloads()

	/**
	 * Not found, unavailable and failed answer 404, 503 and 502.
	 *
	 * @return void
	 */
	public function testEachRefusalHasItsStatus(): void {
		$cases = [
			SubmissionReceiptServiceInterface::RECEIPT_NOT_FOUND => [404, 'not_found'],
			SubmissionReceiptServiceInterface::RECEIPT_PDF_UNAVAILABLE => [503, 'pdf_unavailable'],
			SubmissionReceiptServiceInterface::RECEIPT_PDF_FAILED => [502, 'pdf_failed'],
		];
		foreach ($cases as $status => [$code, $error]) {
			$receipts = $this->createMock(SubmissionReceiptServiceInterface::class);
			$receipts->method('receiptPdf')->willReturn(['status' => $status]);

			$response = $this->controller(subject: ['subjectRef' => 'bram'], receipts: $receipts)->pdf('msg-1');

			$this->assertSame($code, $response->getStatus(), $status);
			$this->assertSame(['error' => $error], $response->getData(), $status);
		}
	}//end testEachRefusalHasItsStatus()
}//end class
