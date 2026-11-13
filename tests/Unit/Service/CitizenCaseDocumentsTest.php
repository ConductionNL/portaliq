<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CitizenCaseDocuments;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalCaseDocumentReader;
use OCA\Portaliq\Service\PortalFileReader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The documents on a resident's case: the organisation's (from a provider or
 * the released files), then the resident's own uploads; and opening one only
 * when it is listed, auditing the download.
 */
#[CoversClass(CitizenCaseDocuments::class)]
class CitizenCaseDocumentsTest extends TestCase {

	private PortalFileReader&MockObject $files;
	private PortalCaseDocumentReader&MockObject $published;
	private PortalAuditHook&MockObject $audit;

	private const FOLDER = ['register' => 'reg', 'schema' => 'case', 'id' => 'c-1'];

	protected function setUp(): void {
		$this->files = $this->createMock(PortalFileReader::class);
		$this->published = $this->createMock(PortalCaseDocumentReader::class);
		$this->audit = $this->createMock(PortalAuditHook::class);
	}

	private function documents(): CitizenCaseDocuments {
		return new CitizenCaseDocuments($this->files, $this->published, $this->audit);
	}

	private function uploads(array $files): void {
		$this->files->method('listTaggedFiles')->with('reg', 'case', 'c-1', 'portal:from-applicant')->willReturn($files);
	}

	public function testProviderDocumentsListDecisionsNewestFirstThenOthersThenUploads(): void {
		$this->published->expects($this->once())->method('entries')->with('zaken', 'caseDocuments', 'c-1')->willReturn([
			['id' => 'd-old', 'title' => 'Old decision', 'kind' => 'decision', 'date' => '2026-01-01', 'file' => ['x']],
			['id' => 'n-1', 'title' => 'Letter', 'kind' => 'document', 'date' => '2026-03-01', 'file' => ['y']],
			['id' => 'd-new', 'title' => 'New decision', 'kind' => 'decision', 'date' => '2026-02-01', 'file' => ['z']],
		]);
		$this->files->expects($this->never())->method('listReleasedFiles');
		$this->uploads([['id' => 9, 'name' => 'photo.jpg', 'size' => 120], ['name' => 'no id']]);

		$listed = $this->documents()->listFor(['app' => 'zaken', 'documents' => ['provider' => 'caseDocuments']], 'reg', 'case', 'c-1');

		$this->assertSame(
			[
				['id' => 'd-new', 'title' => 'New decision', 'kind' => 'decision', 'date' => '2026-02-01'],
				['id' => 'd-old', 'title' => 'Old decision', 'kind' => 'decision', 'date' => '2026-01-01'],
				['id' => 'n-1', 'title' => 'Letter', 'kind' => 'document', 'date' => '2026-03-01'],
				['id' => 'upload:9', 'title' => 'photo.jpg', 'kind' => 'yours', 'date' => '', 'size' => 120],
			],
			$listed
		);
	}

	public function testReleasedFilesAreListedOnlyWhenDownloadsAreOn(): void {
		$this->files->method('listReleasedFiles')->with('reg', 'case', 'c-1')->willReturn([
			['id' => 'f1', 'name' => 'Decision.pdf', 'size' => '12'],
		]);
		$this->uploads([]);

		$this->assertSame(
			[['id' => 'released:f1', 'title' => 'Decision.pdf', 'kind' => 'document', 'date' => '']],
			$this->documents()->listFor(['filesDownload' => true], 'reg', 'case', 'c-1')
		);
		$this->assertSame([], $this->documents()->listFor(['filesDownload' => 'yes'], 'reg', 'case', 'c-1'));
		$this->assertSame([], $this->documents()->listFor([], 'reg', 'case', 'c-1'));
	}

	public function testAListedReleasedFileStreamsAndIsAudited(): void {
		$this->files->method('listReleasedFiles')->willReturn([['id' => 'f1', 'name' => 'a.pdf']]);
		$stream = $this->createMock(StreamResponse::class);
		$this->files->expects($this->once())->method('streamFile')->with('reg', 'case', 'c-1', 'f1')->willReturn($stream);
		$this->audit->expects($this->once())->method('download')->with('subj-1', 'org-1', 'reg', 'case', 'c-1');

		$context = ['filesDownload' => true, 'subject' => ['subjectRef' => 'subj-1', 'organisation' => 'org-1']];

		$this->assertSame($stream, $this->documents()->open($context, 'reg', 'case', 'c-1', 'released:f1'));
	}

	public function testAnUploadIsOpenedFromTheUploadsOnly(): void {
		$this->files->expects($this->never())->method('listReleasedFiles');
		$this->published->expects($this->never())->method('entries');
		$this->uploads([['id' => '7', 'name' => 'mine.pdf']]);
		$stream = $this->createMock(StreamResponse::class);
		$this->files->expects($this->once())->method('streamFile')->with('reg', 'case', 'c-1', '7')->willReturn($stream);
		$this->audit->expects($this->once())->method('download')->with('', '', 'reg', 'case', 'c-1');

		$this->assertSame($stream, $this->documents()->stream([], 'reg', 'case', 'c-1', 'upload:7'));
	}

	public function testAnUnlistedOrEmptyDocumentIsNotFoundAndNotAudited(): void {
		$this->files->method('listReleasedFiles')->willReturn([['id' => 'f1']]);
		$this->files->expects($this->never())->method('streamFile');
		$this->audit->expects($this->never())->method('download');

		foreach (['released:f2', 'f1', ''] as $documentId) {
			$response = $this->documents()->open(['filesDownload' => true], 'reg', 'case', 'c-1', $documentId);

			$this->assertInstanceOf(JSONResponse::class, $response);
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['error' => 'not_found'], $response->getData());
		}
	}

	public function testAFileThatCannotBeStreamedIsNotFoundAndNotAudited(): void {
		$this->files->method('listReleasedFiles')->willReturn([['id' => 'f1']]);
		$this->files->method('streamFile')->willReturn(null);
		$this->audit->expects($this->never())->method('download');

		$this->assertSame(
			Http::STATUS_NOT_FOUND,
			$this->documents()->open(['filesDownload' => true], 'reg', 'case', 'c-1', 'released:f1')->getStatus()
		);
	}

	public function testAnUnauthorisedContextIsPassedThrough(): void {
		$refusal = new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		$this->files->expects($this->never())->method('streamFile');

		$this->assertSame($refusal, $this->documents()->open($refusal, 'reg', 'case', 'c-1', 'released:f1'));
	}

	public function testAnyOtherRefusalBecomesNotFound(): void {
		$forbidden = new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		$this->files->expects($this->never())->method('streamFile');

		$response = $this->documents()->open($forbidden, 'reg', 'case', 'c-1', 'released:f1');

		$this->assertNotSame($forbidden, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
