<?php

/**
 * CitizenDocumentUpload tests.
 *
 * These two routines moved out of CitizenCaseController to bring that class
 * under phpmd's complexity threshold. Moving them changed no behaviour, but it
 * did make a set of paths reachable that a controller test could only get at
 * awkwardly, and which consequently were never covered: the basename fallback
 * for a client-supplied `.`, `..` or empty name, the three failure modes of a
 * multipart upload, and the collision counter past its first step.
 *
 * The basename guard is the reason `read()` exists in the form it does -- the
 * client's path is never trusted -- so it is the one worth pinning.
 *
 * WHAT THESE ACTUALLY CATCH, established by mutating the class and re-running
 * rather than by assuming a passing test proves anything:
 *
 *   dropping '..' from UNUSABLE_NAMES      -> caught
 *   removing the basename() call           -> caught
 *   dropping the `true` from its in_array  -> NOT caught, and cannot be
 *
 * That last one is not a hole to fill. `$fileName` is always a string by then
 * -- `basename((string) ...)` guarantees it -- and against an all-string
 * haystack PHP 8's loose comparison agrees with the strict one on every input,
 * including '0'. The strict flag is correct style here, not a behavioural
 * guard, and no test can distinguish it. Said plainly so the next person does
 * not go looking for the case that would.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CitizenDocumentUpload;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\CitizenDocumentUpload
 */
final class CitizenDocumentUploadTest extends TestCase {
	/**
	 * Temp files created by a test, removed in tearDown.
	 *
	 * @var array<int, string>
	 */
	private array $tempFiles = [];

	/**
	 * Remove whatever a test wrote to the system temp directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		$this->tempFiles = [];
		parent::tearDown();
	}//end tearDown()

	/**
	 * A readable upload on disk, as PHP would have left it.
	 *
	 * @param string $content What the file holds.
	 *
	 * @return string The temp path.
	 */
	private function uploadedTempFile(string $content): string {
		$path = tempnam(sys_get_temp_dir(), 'pq-upload-');
		$this->tempFiles[] = $path;
		file_put_contents($path, $content);
		return $path;
	}//end uploadedTempFile()

	/**
	 * The happy path: a readable upload comes back whole.
	 *
	 * @return void
	 */
	public function testAReadableUploadComesBackWithItsNameAndContent(): void {
		$path = $this->uploadedTempFile('the citizen attached this');

		$this->assertSame(
			['name' => 'besluit.pdf', 'content' => 'the citizen attached this'],
			CitizenDocumentUpload::read(['error' => 0, 'tmp_name' => $path, 'name' => 'besluit.pdf'])
		);
	}//end testAReadableUploadComesBackWithItsNameAndContent()

	/**
	 * THE GUARD. A name that is a path, or is no name at all, never reaches
	 * the file writer as given.
	 *
	 * @param string $declared What the client called the file.
	 * @param string $expected What it is stored as.
	 *
	 * @dataProvider untrustedNames
	 *
	 * @return void
	 */
	public function testTheClientsPathIsNeverTrusted(string $declared, string $expected): void {
		$path = $this->uploadedTempFile('x');

		$upload = CitizenDocumentUpload::read(
			['error' => 0, 'tmp_name' => $path, 'name' => $declared]
		);

		$this->assertNotNull($upload);
		$this->assertSame($expected, $upload['name']);
	}//end testTheClientsPathIsNeverTrusted()

	/**
	 * Names a client may send and what each must become.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function untrustedNames(): array {
		return [
			'a traversal is reduced to its basename' => ['../../etc/passwd', 'passwd'],
			'a bare parent reference becomes the fallback' => ['..', 'upload'],
			'a bare current reference becomes the fallback' => ['.', 'upload'],
			'an empty name becomes the fallback' => ['', 'upload'],
			'a directory path keeps only the leaf' => ['stukken/besluit.pdf', 'besluit.pdf'],
			'an ordinary name is left alone' => ['besluit.pdf', 'besluit.pdf'],
		];
	}//end untrustedNames()

	/**
	 * Every way an upload can be unusable reads as "no file", not as an empty
	 * one -- the controller turns null into a refusal the citizen can read.
	 *
	 * @return void
	 */
	public function testAnUnusableUploadIsNull(): void {
		$path = $this->uploadedTempFile('x');

		$this->assertNull(CitizenDocumentUpload::read(null), 'no multipart part at all');
		$this->assertNull(CitizenDocumentUpload::read([]), 'an empty part defaults error to 1');
		$this->assertNull(
			CitizenDocumentUpload::read(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => $path]),
			'PHP reported an upload error'
		);
		$this->assertNull(
			CitizenDocumentUpload::read(['error' => 0, 'tmp_name' => '']),
			'no temp path'
		);
		$this->assertNull(
			CitizenDocumentUpload::read(
				['error' => 0, 'tmp_name' => $path . '-does-not-exist']
			),
			'the temp path is not readable'
		);
	}//end testAnUnusableUploadIsNull()

	/**
	 * An empty file is still a file. It must NOT read as "no upload", or a
	 * zero-byte attachment would silently become a refusal.
	 *
	 * @return void
	 */
	public function testAnEmptyFileIsStillAnUpload(): void {
		$upload = CitizenDocumentUpload::read(
			['error' => 0, 'tmp_name' => $this->uploadedTempFile(''), 'name' => 'leeg.pdf']
		);

		$this->assertSame(['name' => 'leeg.pdf', 'content' => ''], $upload);
	}//end testAnEmptyFileIsStillAnUpload()

	/**
	 * A name the case does not hold is used as it is.
	 *
	 * @return void
	 */
	public function testAFreeNameIsKept(): void {
		$this->assertSame(
			'besluit.pdf',
			CitizenDocumentUpload::uniqueName('besluit.pdf', [['name' => 'ander.pdf']])
		);
	}//end testAFreeNameIsKept()

	/**
	 * The counter walks past every taken name, not just the first.
	 *
	 * @return void
	 */
	public function testTheCounterWalksPastEveryTakenName(): void {
		$existing = [
			['name' => 'besluit.pdf'],
			['name' => 'besluit-2.pdf'],
			['name' => 'besluit-3.pdf'],
		];

		$this->assertSame(
			'besluit-4.pdf',
			CitizenDocumentUpload::uniqueName('besluit.pdf', $existing)
		);
	}//end testTheCounterWalksPastEveryTakenName()

	/**
	 * The suffix goes before the extension, and a name without one still
	 * gets a counter.
	 *
	 * @return void
	 */
	public function testTheSuffixGoesBeforeTheExtension(): void {
		$this->assertSame(
			'archive.tar-2.gz',
			CitizenDocumentUpload::uniqueName('archive.tar.gz', [['name' => 'archive.tar.gz']]),
			'pathinfo splits on the LAST dot'
		);
		$this->assertSame(
			'bijlage-2',
			CitizenDocumentUpload::uniqueName('bijlage', [['name' => 'bijlage']]),
			'no extension, no trailing dot'
		);
	}//end testTheSuffixGoesBeforeTheExtension()

	/**
	 * A malformed entry in the file list is skipped rather than fatal: the
	 * list comes from OpenRegister and this code does not own its shape.
	 *
	 * @return void
	 */
	public function testMalformedEntriesInTheFileListAreIgnored(): void {
		$existing = [
			[],
			['name' => null],
			['name' => 123],
			['name' => ''],
			['name' => 'besluit.pdf'],
		];

		$this->assertSame(
			'besluit-2.pdf',
			CitizenDocumentUpload::uniqueName('besluit.pdf', $existing)
		);
	}//end testMalformedEntriesInTheFileListAreIgnored()
}//end class
