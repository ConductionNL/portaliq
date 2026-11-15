<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Kernel\Support;

use OCA\Portaliq\Kernel\Support\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * An uploaded file is read only when PHP accepted it, under its base name.
 *
 * @covers \OCA\Portaliq\Kernel\Support\UploadedFile
 */
class UploadedFileTest extends TestCase {
	private string $tmp = '';

	protected function setUp(): void {
		$this->tmp = (string)tempnam(sys_get_temp_dir(), 'pq-upload');
		file_put_contents($this->tmp, "%PDF-1.7\nbody");
	}//end setUp()

	protected function tearDown(): void {
		if ($this->tmp !== '' && file_exists($this->tmp) === true) {
			unlink($this->tmp);
		}
	}//end tearDown()

	public function testAnAcceptedUploadIsReadUnderItsBaseName(): void {
		$read = (new UploadedFile())->read(uploaded: ['tmp_name' => $this->tmp, 'error' => 0, 'name' => '../../etc/aanvraag.pdf']);

		$this->assertSame(['name' => 'aanvraag.pdf', 'content' => "%PDF-1.7\nbody"], $read);
	}//end testAnAcceptedUploadIsReadUnderItsBaseName()

	public function testANumericStringErrorZeroIsAccepted(): void {
		$read = (new UploadedFile())->read(uploaded: ['tmp_name' => $this->tmp, 'error' => '0', 'name' => 'a.txt']);

		$this->assertSame('a.txt', $read['name'] ?? null);
	}//end testANumericStringErrorZeroIsAccepted()

	public function testAMissingOrUnusableNameBecomesUpload(): void {
		$file = new UploadedFile();

		$this->assertSame('upload', $file->read(uploaded: ['tmp_name' => $this->tmp, 'error' => 0])['name'] ?? null);
		$this->assertSame('upload', $file->read(uploaded: ['tmp_name' => $this->tmp, 'error' => 0, 'name' => ''])['name'] ?? null);
		$this->assertSame('upload', $file->read(uploaded: ['tmp_name' => $this->tmp, 'error' => 0, 'name' => '..'])['name'] ?? null);
		$this->assertSame('upload', $file->read(uploaded: ['tmp_name' => $this->tmp, 'error' => 0, 'name' => '/'])['name'] ?? null);
	}//end testAMissingOrUnusableNameBecomesUpload()

	public function testAFailedOrMissingUploadReadsAsNull(): void {
		$file = new UploadedFile();

		$this->assertNull($file->read(uploaded: ['tmp_name' => $this->tmp, 'error' => UPLOAD_ERR_PARTIAL, 'name' => 'a.pdf']));
		$this->assertNull($file->read(uploaded: ['tmp_name' => $this->tmp, 'name' => 'a.pdf']));
		$this->assertNull($file->read(uploaded: ['error' => 0, 'name' => 'a.pdf']));
		$this->assertNull($file->read(uploaded: ['tmp_name' => $this->tmp . '-gone', 'error' => 0, 'name' => 'a.pdf']));
		$this->assertNull($file->read(uploaded: null));
		$this->assertNull($file->read(uploaded: []));
	}//end testAFailedOrMissingUploadReadsAsNull()
}//end class
