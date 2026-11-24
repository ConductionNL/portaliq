<?php

/**
 * Portal Audience Media Controller Test
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Controller
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
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
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

	/**
	 * The media reader the controller hands a declared group to.
	 *
	 * @var MockObject&AudienceMediaReader
	 */
	private MockObject $media;


	/**
	 * The controller for a session subject, with one learniq documents page.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject, or null for no session.
	 *
	 * @return PortalAudienceMediaController
	 */
	private function controller(?array $subject): PortalAudienceMediaController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturn('Bearer x');
		$session = $this->createMock(originalClassName: PortalSessionServiceInterface::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$blocks   = [
			['type' => 'tasks', 'extraGroups' => [['label' => 'X', 'register' => 'other', 'schema' => 'secret']]],
			[
				'type'        => 'documents',
				'collection'  => 'kinderen',
				'extraGroups' => [['label' => 'Van school', 'register' => 'school-media', 'schema' => 'paper']],
			],
		];
		$registry = $this->createMock(originalClassName: PortalContributionRegistryInterface::class);
		$pages    = [['id' => 'documenten', 'blocks' => $blocks]];
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'learniq', 'pages' => $pages]]]);
		$this->media = $this->createMock(originalClassName: AudienceMediaReader::class);

		return new PortalAudienceMediaController(request: $request, registry: $registry, session: $session, media: $this->media);
	}//end controller()


	/**
	 * Without a session the answer is 401.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheAnswerIs401(): void {
		$controller = $this->controller(subject: null);
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->list(register: 'school-media', schema: 'paper')->getStatus());
		$open = $controller->open(register: 'school-media', schema: 'paper', id: 'guide');
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $open->getStatus());
	}//end testWithoutASessionTheAnswerIs401()


	/**
	 * A pair no documents block names is 404, and the reader is never asked.
	 *
	 * @return void
	 */
	public function testARegisterAndSchemaNoDocumentsBlockNamesIsNotFound(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'fatima', 'provider' => 'nextcloud']);
		$this->media->expects($this->never())->method('list');
		$this->media->expects($this->never())->method('open');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->list(register: 'other', schema: 'secret')->getStatus());
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->list(register: 'school-media', schema: 'letter')->getStatus());
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $controller->open(register: 'other', schema: 'secret', id: 'x')->getStatus());
	}//end testARegisterAndSchemaNoDocumentsBlockNamesIsNotFound()


	/**
	 * A declared group lists and opens through the reader; nothing to open is 404.
	 *
	 * @return void
	 */
	public function testADeclaredGroupListsAndOpensThroughTheReader(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'fatima', 'provider' => 'nextcloud']);
		$stream     = $this->createMock(originalClassName: StreamResponse::class);
		$this->media->method('list')->willReturn([['id' => 'guide', 'title' => 'Schoolgids']]);
		$this->media->method('open')->willReturnOnConsecutiveCalls($stream, null);

		$this->assertSame(expected: 'guide', actual: $controller->list(register: 'school-media', schema: 'paper')->getData()['documents'][0]['id']);
		$this->assertSame(expected: $stream, actual: $controller->open(register: 'school-media', schema: 'paper', id: 'guide'));
		$none = $controller->open(register: 'school-media', schema: 'paper', id: 'letter5');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $none->getStatus());
	}//end testADeclaredGroupListsAndOpensThroughTheReader()
}//end class
