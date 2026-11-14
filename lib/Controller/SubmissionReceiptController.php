<?php

/**
 * Portaliq Submission Receipt Controller
 *
 * A resident's own submission receipt as a PDF.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/capability-rows-without-a-requirement/tasks.md#t04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Http\PdfDownloadResponse;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Kernel\Contracts\SubmissionReceiptServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Answers only for a receipt of the bearer's own subject: someone else's
 * receipt and an unknown id answer the same 404, and nothing is rendered.
 *
 * @spec openspec/changes/capability-rows-without-a-requirement/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
 */
class SubmissionReceiptController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionServiceInterface $session Resolves the subject from the bearer.
	 * @param SubmissionReceiptServiceInterface $receipts Reads and renders the receipt.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionServiceInterface $session,
		private readonly SubmissionReceiptServiceInterface $receipts,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The receipt as a PDF: its reference, moment of receipt and answers.
	 *
	 * @param string $id The receipt message.
	 *
	 * @return Response The file, or 401, 404, 503 when OpenRegister cannot render, 502 when the render failed.
	 *
	 * @spec openspec/changes/capability-rows-without-a-requirement/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function pdf(string $id): Response {
		$signedIn = new SignedInSubject();
		$subject  = $signedIn->resolve(session: $this->session, request: $this->request);
		if ($subject === null) {
			return $signedIn->unauthenticated();
		}

		$result = $this->receipts->receiptPdf(subject: $subject, id: $id);
		$bytes  = (string)($result['bytes'] ?? '');
		$name   = (string)($result['name'] ?? '');

		return match ($result['status']) {
			SubmissionReceiptServiceInterface::RECEIPT_PDF => new PdfDownloadResponse(bytes: $bytes, name: $name),
			SubmissionReceiptServiceInterface::RECEIPT_PDF_UNAVAILABLE => new JSONResponse(['error' => 'pdf_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE),
			SubmissionReceiptServiceInterface::RECEIPT_PDF_FAILED => new JSONResponse(['error' => 'pdf_failed'], Http::STATUS_BAD_GATEWAY),
			default => new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND),
		};
	}//end pdf()
}//end class
