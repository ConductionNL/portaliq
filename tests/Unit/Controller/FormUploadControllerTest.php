<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\FormUploadController;
use OCA\Portaliq\Kernel\Contracts\FormUploadGrantsInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFileFieldPolicyInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFileWriterInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The anonymous upload of a landing-page form's file: the token is the whole
 * authorisation, the field's limits are checked before anything is written,
 * and a file lands on the submission's own object, tagged with its field.
 *
 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
 */
class FormUploadControllerTest extends TestCase {
	/**
	 * The token store.
	 *
	 * @var FormUploadGrantsInterface&MockObject
	 */
	private FormUploadGrantsInterface&MockObject $grants;

	/**
	 * The file policy.
	 *
	 * @var PortalFileFieldPolicyInterface&MockObject
	 */
	private PortalFileFieldPolicyInterface&MockObject $policy;

	/**
	 * The file writer.
	 *
	 * @var PortalFileWriterInterface&MockObject
	 */
	private PortalFileWriterInterface&MockObject $files;

	/**
	 * The doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->grants = $this->createMock(originalClassName: FormUploadGrantsInterface::class);
		$this->policy = $this->createMock(originalClassName: PortalFileFieldPolicyInterface::class);
		$this->files  = $this->createMock(originalClassName: PortalFileWriterInterface::class);
	}//end setUp()

	/**
	 * A missing, unknown or used token, or a field it does not name, reads no file.
	 *
	 * @return void
	 */
	public function testWithoutAGrantNothingIsRead(): void {
		$this->grants->method('grantFor')->willReturn(null);
		$this->policy->expects($this->never())->method('readUpload');
		$this->files->expects($this->never())->method('attachFile');

		$response = $this->controller(token: 'tok-unknown')->upload(field: 'photo');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'upload_not_allowed'], actual: $response->getData());
	}//end testWithoutAGrantNothingIsRead()

	/**
	 * A file over the field's size is refused and the token keeps its upload.
	 *
	 * @return void
	 */
	public function testAFileTheFieldRefusesKeepsTheUpload(): void {
		$this->granted();
		$this->policy->method('readUpload')->willReturn(['name' => 'big.jpg', 'content' => 'bytes']);
		$this->policy->method('refusal')->willReturn(PortalFileFieldPolicyInterface::ERROR_TOO_LARGE);
		$this->grants->expects($this->never())->method('use');
		$this->files->expects($this->never())->method('attachFile');

		$response = $this->controller()->upload(field: 'photo');

		$this->assertSame(expected: Http::STATUS_REQUEST_ENTITY_TOO_LARGE, actual: $response->getStatus());
	}//end testAFileTheFieldRefusesKeepsTheUpload()

	/**
	 * No multipart file answers 400.
	 *
	 * @return void
	 */
	public function testNoFileAnswers400(): void {
		$this->granted();
		$this->policy->method('readUpload')->willReturn(null);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller()->upload(field: 'photo')->getStatus());
	}//end testNoFileAnswers400()

	/**
	 * An accepted file uses the upload and lands on the token's object, tagged with its field.
	 *
	 * @return void
	 */
	public function testAnAcceptedFileLandsOnTheSubmissionsObject(): void {
		$this->granted();
		$this->policy->method('readUpload')->willReturn(['name' => 'foto.jpg', 'content' => 'bytes']);
		$this->policy->method('refusal')->willReturn(null);
		$this->grants->expects($this->once())->method('use')->with('tok-abc', 'photo')->willReturn(true);
		$this->files->expects($this->once())->method('attachFile')
			->with('pipelinq', 'lead', 'uuid-1', 'foto.jpg', 'bytes', ['portaliq-form-field:photo'])
			->willReturn(['id' => 42, 'name' => 'foto.jpg', 'size' => 5]);

		$response = $this->controller()->upload(field: 'photo');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['file' => ['id' => '42', 'name' => 'foto.jpg', 'size' => 5], 'field' => 'photo'], actual: $response->getData());
	}//end testAnAcceptedFileLandsOnTheSubmissionsObject()

	/**
	 * Two requests at once: the second finds the upload used and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUploadUsedMeanwhileWritesNothing(): void {
		$this->granted();
		$this->policy->method('readUpload')->willReturn(['name' => 'foto.jpg', 'content' => 'bytes']);
		$this->policy->method('refusal')->willReturn(null);
		$this->grants->method('use')->willReturn(false);
		$this->files->expects($this->never())->method('attachFile');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller()->upload(field: 'photo')->getStatus());
	}//end testAnUploadUsedMeanwhileWritesNothing()

	/**
	 * A write that fails, an antivirus refusal among them, answers 502.
	 *
	 * @return void
	 */
	public function testAFailedWriteAnswers502(): void {
		$this->granted();
		$this->policy->method('readUpload')->willReturn(['name' => 'foto.jpg', 'content' => 'bytes']);
		$this->policy->method('refusal')->willReturn(null);
		$this->grants->method('use')->willReturn(true);
		$this->files->method('attachFile')->willReturn(null);

		$this->assertSame(expected: Http::STATUS_BAD_GATEWAY, actual: $this->controller()->upload(field: 'photo')->getStatus());
	}//end testAFailedWriteAnswers502()

	/**
	 * The token grants the photo field of uuid-1.
	 *
	 * @return void
	 */
	private function granted(): void {
		$this->grants->method('grantFor')->with('tok-abc', 'photo')->willReturn(
			[
				'target' => ['register' => 'pipelinq', 'schema' => 'lead', 'id' => 'uuid-1'],
				'config' => ['type' => 'file', 'maxSizeMb' => 1],
			]
		);
	}//end granted()

	/**
	 * The controller over a request carrying the token.
	 *
	 * @param string $token The token header.
	 *
	 * @return FormUploadController
	 */
	private function controller(string $token = 'tok-abc'): FormUploadController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === FormUploadController::TOKEN_HEADER ? $token : '')
		);

		return new FormUploadController(request: $request, grants: $this->grants, policy: $this->policy, fileWriter: $this->files);
	}//end controller()
}//end class
