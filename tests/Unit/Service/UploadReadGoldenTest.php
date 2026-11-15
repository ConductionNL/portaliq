<?php

/**
 * The two upload readers, PortalFileFieldPolicy::readUpload() and
 * CitizenDocumentUpload::read(): every case, side by side.
 *
 * @category Tests
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\CitizenDocumentUpload;
use OCA\Portaliq\Service\PortalFileFieldPolicy;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Both readers answer every upload the same way, as recorded.
 */
class UploadReadGoldenTest extends TestCase {
	/**
	 * A temporary file holding `bytes`.
	 *
	 * @var string
	 */
	private string $tmp = '';

	/**
	 * Write the temporary file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tmp = (string)tempnam(sys_get_temp_dir(), 'upload-golden');
		file_put_contents($this->tmp, 'bytes');
	}//end setUp()

	/**
	 * Remove the temporary file.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->tmp);
		parent::tearDown();
	}//end tearDown()

	/**
	 * What PortalFileFieldPolicy::readUpload() reads from a request holding the upload as `file`.
	 *
	 * @param mixed $uploaded The upload.
	 *
	 * @return array{name: string, content: string}|null
	 */
	private function policyRead(mixed $uploaded): ?array {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturnCallback(static fn (string $key): mixed => ($key === 'file' ? $uploaded : null));

		return (new PortalFileFieldPolicy($this->createMock(ITimeFactory::class), $this->createMock(PortalSchemaReader::class)))->readUpload($request);
	}//end policyRead()

	/**
	 * Every case reads the same through both readers.
	 *
	 * @return void
	 */
	public function testEveryUploadReadsAsRecordedThroughBothReaders(): void {
		$cases = [
			'good'          => [['tmp_name' => $this->tmp, 'error' => 0, 'name' => 'dir/../Essay.pdf'], ['name' => 'Essay.pdf', 'content' => 'bytes']],
			'no name'       => [['tmp_name' => $this->tmp, 'error' => 0], ['name' => 'upload', 'content' => 'bytes']],
			'dot dot'       => [['tmp_name' => $this->tmp, 'error' => 0, 'name' => '..'], ['name' => 'upload', 'content' => 'bytes']],
			'dot'           => [['tmp_name' => $this->tmp, 'error' => 0, 'name' => '.'], ['name' => 'upload', 'content' => 'bytes']],
			'empty name'    => [['tmp_name' => $this->tmp, 'error' => 0, 'name' => ''], ['name' => 'upload', 'content' => 'bytes']],
			'no error key'  => [['tmp_name' => $this->tmp, 'name' => 'a.pdf'], null],
			'an error'      => [['tmp_name' => $this->tmp, 'error' => UPLOAD_ERR_PARTIAL, 'name' => 'a.pdf'], null],
			'no tmp'        => [['tmp_name' => '', 'error' => 0, 'name' => 'a.pdf'], null],
			'unreadable'    => [['tmp_name' => $this->tmp . '-gone', 'error' => 0, 'name' => 'a.pdf'], null],
			'nothing'       => [null, null],
		];
		foreach ($cases as $label => [$uploaded, $expected]) {
			$this->assertSame($expected, $this->policyRead(uploaded: $uploaded), 'policy: ' . $label);
			$this->assertSame($expected, (new CitizenDocumentUpload())->read($uploaded), 'citizen: ' . $label);
		}
	}//end testEveryUploadReadsAsRecordedThroughBothReaders()
}//end class
