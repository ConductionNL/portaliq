<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ContentMediaController;
use OCA\Portaliq\Service\Cms\MediaFile;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * site-page-seo-history-and-media T07 (REQ-SPH-004): GET
 * /api/content/media/{id} streams a published item of the resolved portal and
 * answers not found otherwise, with one answer for every kind of miss.
 *
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */
class ContentMediaControllerTest extends TestCase {

	public function testAPublishedItemIsStreamed(): void {
		$stream = $this->createMock(StreamResponse::class);

		$this->assertSame($stream, $this->controller(portal: ['slug' => 'gemeente'], stream: $stream)->show(id: 'm1'));
	}//end testAPublishedItemIsStreamed()

	public function testADraftItemIsNotFound(): void {
		$response = $this->controller(portal: ['slug' => 'gemeente'], stream: null)->show(id: 'm1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());
	}//end testADraftItemIsNotFound()

	public function testNoServingPortalIsNotFound(): void {
		$response = $this->controller(portal: null, stream: $this->createMock(StreamResponse::class))->show(id: 'm1', portal: 'elders');

		$this->assertSame(404, $response->getStatus());
	}//end testNoServingPortalIsNotFound()

	/**
	 * The controller over a resolved portal and a stream answer.
	 *
	 * @param array|null          $portal The resolved portal.
	 * @param StreamResponse|null $stream What the media file answers.
	 *
	 * @return ContentMediaController
	 */
	private function controller(?array $portal, ?StreamResponse $stream): ContentMediaController {
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn($portal);
		$media = $this->createMock(MediaFile::class);
		$media->method('stream')->willReturn($stream);

		return new ContentMediaController($this->createMock(IRequest::class), $resolver, $media);
	}//end controller()
}//end class
