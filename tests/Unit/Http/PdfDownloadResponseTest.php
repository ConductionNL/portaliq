<?php

/**
 * Tests for the PDF download response.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Http
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Http;

use OCA\Portaliq\Http\PdfDownloadResponse;
use PHPUnit\Framework\TestCase;

/**
 * The response hands back the bytes verbatim, declares a PDF attachment of its
 * length, forbids caching, and reduces the file name to a safe slug.
 *
 * @covers \OCA\Portaliq\Http\PdfDownloadResponse
 */
final class PdfDownloadResponseTest extends TestCase {

	public function testItServesTheBytesAndDeclaresAPdfAttachment(): void {
		$bytes    = "%PDF-1.7\nbinary\x00body";
		$response = new PdfDownloadResponse($bytes, 'Mijn Verzoek 2026.pdf');

		// render() returns the bytes untouched.
		$this->assertSame($bytes, $response->render());

		$headers = $response->getHeaders();
		$this->assertSame('application/pdf', $headers['Content-Type']);
		$this->assertSame('attachment; filename="mijn-verzoek-2026-pdf.pdf"', $headers['Content-Disposition']);
		$this->assertSame((string) strlen($bytes), $headers['Content-Length']);
		$this->assertSame('private, no-store', $headers['Cache-Control']);
	}//end testItServesTheBytesAndDeclaresAPdfAttachment()

	public function testTheFileNameIsReducedToASafeSlug(): void {
		$response = new PdfDownloadResponse('x', 'Aanvraag #42 — déjà/vu!.PDF');

		// Runs of non-alphanumerics collapse to single hyphens, trimmed, and
		// one `.pdf` suffix is added.
		$this->assertSame('aanvraag-42-d-j-vu-pdf.pdf', $response->fileName());
	}//end testTheFileNameIsReducedToASafeSlug()

	public function testANameWithNothingUsableFallsBackToExport(): void {
		$response = new PdfDownloadResponse('x', '!!!');

		$this->assertSame('export.pdf', $response->fileName());
	}//end testANameWithNothingUsableFallsBackToExport()
}//end class
