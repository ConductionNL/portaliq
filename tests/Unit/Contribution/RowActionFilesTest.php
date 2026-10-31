<?php

/**
 * The `files` declaration of an endpoint row action (REQ-RAF-001).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Contribution
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
 * @spec openspec/changes/row-action-carries-files/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-may-carry-the-files-the-resident-adds-req-raf-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\RowActionFiles;
use OCA\Portaliq\Contribution\RowActionInputs;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Normalising the declaration and checking the uploads.
 */
class RowActionFilesTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function endpointAction(array $files): array {
		return [
			'id' => 'beantwoordVraag',
			'endpoint' => '/apps/dossiq/api/portal/woo/answer',
			'method' => 'POST',
			'rowField' => 'requestId',
			'fields' => ['answer'],
			'files' => $files,
		];
	}//end endpointAction()

	private function request(mixed $uploaded): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturnCallback(static fn (string $key): mixed => ($key === 'attachments' ? $uploaded : []));

		return $request;
	}//end request()

	public function testAWellFormedDeclarationIsKeptWithItsBounds(): void {
		$action = (new RowActionFiles())->normalise(action: $this->endpointAction(['field' => 'attachments', 'max' => 50, 'maxBytes' => 999999999]), whitelist: ['answer']);

		$this->assertSame(['field' => 'attachments', 'max' => RowActionFiles::HARD_MAX, 'maxBytes' => RowActionFiles::HARD_MAX_BYTES], $action['files']);

		$defaults = (new RowActionFiles())->normalise(action: $this->endpointAction(['field' => 'attachments']), whitelist: ['answer']);
		$this->assertSame(['field' => 'attachments', 'max' => RowActionFiles::DEFAULT_MAX, 'maxBytes' => RowActionFiles::DEFAULT_MAX_BYTES], $defaults['files']);
	}//end testAWellFormedDeclarationIsKeptWithItsBounds()

	public function testAFieldThatCouldStandInForTheRowOrATextFieldIsDropped(): void {
		$files = new RowActionFiles();

		foreach ([['field' => 'requestId'], ['field' => 'answer'], ['field' => '../x'], ['field' => 7], 'attachments'] as $declared) {
			$action = $this->endpointAction([]);
			$action['files'] = $declared;
			$this->assertArrayNotHasKey('files', $files->normalise(action: $action, whitelist: ['answer']));
		}
	}//end testAFieldThatCouldStandInForTheRowOrATextFieldIsDropped()

	public function testOnlyAnEndpointRowActionKeepsFiles(): void {
		$inputs = new RowActionInputs();

		$kept = $inputs->normaliseAction(action: $this->endpointAction(['field' => 'attachments']));
		$this->assertSame('attachments', $kept['files']['field']);

		$create = $inputs->normaliseAction(action: ['id' => 'nieuw', 'type' => 'create', 'files' => ['field' => 'attachments']]);
		$this->assertArrayNotHasKey('files', $create);
	}//end testOnlyAnEndpointRowActionKeepsFiles()

	public function testUploadsAreReadInBothShapes(): void {
		$action = (new RowActionFiles())->normalise(action: $this->endpointAction(['field' => 'attachments']), whitelist: ['answer']);

		$single = (new RowActionFiles())->collect(action: $action, request: $this->request(['name' => '../../scan.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/php1', 'size' => 10, 'error' => UPLOAD_ERR_OK]));
		$this->assertNull($single['error']);
		$this->assertSame([['name' => 'scan.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/php1', 'size' => 10]], $single['files']);

		$many = (new RowActionFiles())->collect(action: $action, request: $this->request([
			'name' => ['a.pdf', 'b.pdf', 'c.pdf'],
			'type' => ['application/pdf', 'application/pdf', 'application/pdf'],
			'tmp_name' => ['/tmp/a', '/tmp/b', ''],
			'size' => [1, 2, 0],
			'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
		]));
		$this->assertSame(['a.pdf', 'b.pdf'], array_column($many['files'], 'name'));
	}//end testUploadsAreReadInBothShapes()

	public function testTooManyOrTooLargeIsRefused(): void {
		$action = (new RowActionFiles())->normalise(action: $this->endpointAction(['field' => 'attachments', 'max' => 1, 'maxBytes' => 5]), whitelist: ['answer']);

		$two = (new RowActionFiles())->collect(action: $action, request: $this->request([
			'name' => ['a', 'b'], 'type' => ['t', 't'], 'tmp_name' => ['/tmp/a', '/tmp/b'], 'size' => [1, 1], 'error' => [0, 0],
		]));
		$this->assertSame('too_many_files', $two['error']);
		$this->assertSame([], $two['files']);

		$big = (new RowActionFiles())->collect(action: $action, request: $this->request(['name' => 'a', 'type' => 't', 'tmp_name' => '/tmp/a', 'size' => 6, 'error' => 0]));
		$this->assertSame('file_too_large', $big['error']);
	}//end testTooManyOrTooLargeIsRefused()

	public function testAnActionWithoutFilesReadsNoUploads(): void {
		$request = $this->createMock(IRequest::class);
		$request->expects($this->never())->method('getUploadedFile');

		$this->assertSame(['files' => [], 'error' => null], (new RowActionFiles())->collect(action: ['id' => 'x'], request: $request));
	}//end testAnActionWithoutFilesReadsNoUploads()
}//end class
