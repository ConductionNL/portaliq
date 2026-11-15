<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PageHistoryController;
use OCA\Portaliq\Service\Cms\PageHistory;
use OCA\Portaliq\Service\PageEditorService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * site-page-seo-history-and-media REQ-SPH-003: only a page editor reads a
 * page's history.
 *
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */
class PageHistoryControllerTest extends TestCase {

	public function testAnEditorGetsTheVersions(): void {
		$versions = [['id' => 12, 'publishedAt' => '2026-09-25T10:00:00+00:00', 'by' => 'Anna', 'restorable' => true, 'body' => ['type' => 'grid', 'widgets' => []]]];
		$history  = $this->createMock(PageHistory::class);
		$history->expects($this->once())->method('versions')->with('page-1')->willReturn($versions);

		$response = $this->controller(mayEdit: true, history: $history)->index(id: 'page-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['versions' => $versions], $response->getData());
	}//end testAnEditorGetsTheVersions()

	public function testSomeoneWhoMayNotEditReadsNothing(): void {
		$history = $this->createMock(PageHistory::class);
		$history->expects($this->never())->method('versions');

		$response = $this->controller(mayEdit: false, history: $history)->index(id: 'page-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'not_an_editor'], $response->getData());
	}//end testSomeoneWhoMayNotEditReadsNothing()

	/**
	 * The controller over an editor decision.
	 *
	 * @param bool        $mayEdit Whether the caller may edit.
	 * @param PageHistory $history The history.
	 *
	 * @return PageHistoryController
	 */
	private function controller(bool $mayEdit, PageHistory $history): PageHistoryController {
		$editor = $this->createMock(PageEditorService::class);
		$editor->method('mayEdit')->willReturn($mayEdit);

		return new PageHistoryController($this->createMock(IRequest::class), $editor, $history);
	}//end controller()
}//end class
