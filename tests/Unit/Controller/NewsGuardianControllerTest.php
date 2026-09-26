<?php

/**
 * NewsGuardianControllerTest
 *
 * @category Test
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
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\NewsGuardianController;
use OCA\Portaliq\Service\NewsFeedReader;
use OCA\Portaliq\Service\NewsReadReceiptService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The guardian-facing feed/read/archive endpoints: every handler MUST fail
 * closed 401 without a resolved subject, and never accept a client-supplied
 * subject reference.
 *
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#api-design
 */
class NewsGuardianControllerTest extends TestCase {

	/**
	 * @param array<string, mixed>|null $subject The bearer-resolved subject.
	 */
	private function controller(
		?array $subject,
		?NewsFeedReader $feedReader = null,
		?NewsReadReceiptService $readReceipts = null,
	): NewsGuardianController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new NewsGuardianController(
			$request,
			$session,
			$feedReader ?? $this->createMock(NewsFeedReader::class),
			$readReceipts ?? $this->createMock(NewsReadReceiptService::class)
		);
	}//end controller()

	public function testFeedFailsClosedWithoutAResolvedSubject(): void {
		$feedReader = $this->createMock(NewsFeedReader::class);
		$feedReader->expects($this->never())->method('feedFor');

		$response = $this->controller(null, feedReader: $feedReader)->feed();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testFeedFailsClosedWithoutAResolvedSubject()

	public function testFeedReturnsTheSubjectsOwnFeed(): void {
		$feedReader = $this->createMock(NewsFeedReader::class);
		$feedReader->expects($this->once())->method('feedFor')->with('guardian-anna-devries')->willReturn([['title' => 'X']]);

		$response = $this->controller(['subjectRef' => 'guardian-anna-devries'], feedReader: $feedReader)->feed();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['title' => 'X']], $response->getData());
	}//end testFeedReturnsTheSubjectsOwnFeed()

	public function testMarkReadReturns404WhenTheServiceRefuses(): void {
		$readReceipts = $this->createMock(NewsReadReceiptService::class);
		$readReceipts->method('markRead')->willReturn(false);

		$response = $this->controller(['subjectRef' => 's1'], readReceipts: $readReceipts)->markRead('n1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testMarkReadReturns404WhenTheServiceRefuses()

	public function testMarkReadReturns204OnSuccess(): void {
		$readReceipts = $this->createMock(NewsReadReceiptService::class);
		$readReceipts->method('markRead')->willReturn(true);

		$response = $this->controller(['subjectRef' => 's1'], readReceipts: $readReceipts)->markRead('n1');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testMarkReadReturns204OnSuccess()

	public function testArchiveFailsClosedWithoutAResolvedSubject(): void {
		$response = $this->controller(null)->archive();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testArchiveFailsClosedWithoutAResolvedSubject()
}//end class
