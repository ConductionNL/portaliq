<?php

/**
 * Tests for reading and naming the document a citizen adds to their own case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CitizenDocumentUpload;
use PHPUnit\Framework\TestCase;

/**
 * An upload the portal cannot read is refused, and one it can read never
 * replaces a document the case already carries.
 *
 * @covers \OCA\Portaliq\Contribution\CitizenDocumentUpload
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenDocumentUploadTest extends TestCase {

	/**
	 * A readable upload comes back as its bytes and a name taken from the
	 * basename, never from the client's path.
	 *
	 * @return void
	 */
	public function testTheClientsPathIsNeverTrusted(): void {
		$tmp = (string)tempnam(sys_get_temp_dir(), 'citizen-upload');
		file_put_contents($tmp, 'the bytes');

		$read = (new CitizenDocumentUpload())->read(
			['name' => '../../etc/passwd', 'tmp_name' => $tmp, 'error' => 0]
		);

		unlink($tmp);

		$this->assertSame(expected: ['name' => 'passwd', 'content' => 'the bytes'], actual: $read);
	}//end testTheClientsPathIsNeverTrusted()

	/**
	 * Nothing usable is null, not an empty document: no upload at all, an
	 * upload that errored, and an upload whose temp file is gone.
	 *
	 * @return void
	 */
	public function testAnUnusableUploadIsNull(): void {
		$upload = new CitizenDocumentUpload();

		$this->assertNull(actual: $upload->read(null));
		$this->assertNull(actual: $upload->read([]));
		$this->assertNull(actual: $upload->read(['name' => 'a.pdf', 'tmp_name' => '/no/such/file', 'error' => 0]));

		$tmp = (string)tempnam(sys_get_temp_dir(), 'citizen-upload');
		file_put_contents($tmp, 'the bytes');
		$this->assertNull(actual: $upload->read(['name' => 'a.pdf', 'tmp_name' => $tmp, 'error' => 1]));
		unlink($tmp);
	}//end testAnUnusableUploadIsNull()

	/**
	 * A name the case does not use yet is kept as it is.
	 *
	 * @return void
	 */
	public function testAFreeNameIsKept(): void {
		$name = (new CitizenDocumentUpload())->uniqueName(
			[['name' => 'other.pdf'], ['name' => '']],
			'report.pdf'
		);

		$this->assertSame(expected: 'report.pdf', actual: $name);
	}//end testAFreeNameIsKept()

	/**
	 * A name already on the case is counted up until it is free, so the
	 * upload adds a document rather than replacing one.
	 *
	 * @return void
	 */
	public function testATakenNameIsCountedUp(): void {
		$upload = new CitizenDocumentUpload();
		$taken = [['name' => 'report.pdf'], ['name' => 'report-2.pdf']];

		$this->assertSame(expected: 'report-3.pdf', actual: $upload->uniqueName($taken, 'report.pdf'));
		$this->assertSame(expected: 'report-2', actual: $upload->uniqueName([['name' => 'report']], 'report'));
	}//end testATakenNameIsCountedUp()
}//end class
