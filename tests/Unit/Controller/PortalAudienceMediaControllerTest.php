<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalAudienceMediaController;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Service\AudienceMediaReader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * documents-grouped-per-record task 3: a media group is read only for a
 * register and schema a documents block of the subject's own aggregate names.
 *
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
 */
class PortalAudienceMediaControllerTest extends TestCase {

	private MockObject $media;

	private function controller(?array $subject): PortalAudienceMediaController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer x');
		$session = $this->createMock(PortalSessionServiceInterface::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$registry = $this->createMock(PortalContributionRegistryInterface::class);
		$registry->method('aggregateFor')->willReturn(
			[
				'contributions' => [
					[
						'app'   => 'learniq',
						'pages' => [
							['id' => 'documenten', 'blocks' => [
								['type' => 'tasks', 'extraGroups' => [['label' => 'X', 'register' => 'other', 'schema' => 'secret']]],
								['type' => 'documents', 'collection' => 'kinderen', 'extraGroups' => [['label' => 'Van school', 'register' => 'school-media', 'schema' => 'paper']]],
							]],
						],
					],
				],
			]
		);
		$this->media = $this->createMock(AudienceMediaReader::class);

		return new PortalAudienceMediaController($request, $registry, $session, $this->media);
	}

	public function testWithoutASessionTheAnswerIs401(): void {
		$controller = $this->controller(subject: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->list('school-media', 'paper')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->open('school-media', 'paper', 'guide')->getStatus());
	}//end testWithoutASessionTheAnswerIs401()

	public function testARegisterAndSchemaNoDocumentsBlockNamesIsNotFound(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'fatima', 'provider' => 'nextcloud']);
		$this->media->expects($this->never())->method('list');
		$this->media->expects($this->never())->method('open');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->list('other', 'secret')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->list('school-media', 'letter')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->open('other', 'secret', 'x')->getStatus());
	}//end testARegisterAndSchemaNoDocumentsBlockNamesIsNotFound()

	public function testADeclaredGroupListsAndOpensThroughTheReader(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'fatima', 'provider' => 'nextcloud']);
		$stream     = $this->createMock(StreamResponse::class);
		$this->media->method('list')->willReturn([['id' => 'guide', 'title' => 'Schoolgids']]);
		$this->media->method('open')->willReturnOnConsecutiveCalls($stream, null);

		$this->assertSame('guide', $controller->list('school-media', 'paper')->getData()['documents'][0]['id']);
		$this->assertSame($stream, $controller->open('school-media', 'paper', 'guide'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->open('school-media', 'paper', 'letter5')->getStatus());
	}//end testADeclaredGroupListsAndOpensThroughTheReader()
}//end class
