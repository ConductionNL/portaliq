<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SharedBlockController;
use OCA\Portaliq\Kernel\Contracts\PageEditorServiceInterface;
use OCA\Portaliq\Kernel\Contracts\SharedBlockPlacementsInterface;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * site-shared-page-blocks T06: only a page editor reads the pages that place a block.
 *
 * @covers \OCA\Portaliq\Controller\SharedBlockController
 *
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
 */
class SharedBlockControllerTest extends TestCase {

	/**
	 * An editor gets the pages, uncached.
	 *
	 * @return void
	 */
	public function testAnEditorGetsThePages(): void {
		$pages      = [['id' => 'p-home', 'title' => 'Home', 'route' => '/', 'status' => 'published', 'portal' => 'inwoners', 'portalTitle' => 'Inwoners']];
		$placements = $this->createMock(originalClassName: SharedBlockPlacementsInterface::class);
		$placements->expects($this->once())->method('pagesOf')->with('block-1')->willReturn($pages);

		$response = $this->controller(mayEdit: true, placements: $placements)->pages(id: 'block-1');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['results' => $pages, 'total' => 1], actual: $response->getData());
		$this->assertSame(expected: 'private, no-store', actual: $response->getHeaders()['Cache-Control']);
	}//end testAnEditorGetsThePages()

	/**
	 * Someone who may not edit pages reads nothing.
	 *
	 * @return void
	 */
	public function testSomeoneWhoMayNotEditReadsNothing(): void {
		$placements = $this->createMock(originalClassName: SharedBlockPlacementsInterface::class);
		$placements->expects($this->never())->method('pagesOf');

		$response = $this->controller(mayEdit: false, placements: $placements)->pages(id: 'block-1');

		$this->assertSame(expected: 403, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'not_an_editor'], actual: $response->getData());
	}//end testSomeoneWhoMayNotEditReadsNothing()

	/**
	 * A block the editor cannot read answers 404.
	 *
	 * @return void
	 */
	public function testABlockTheEditorCannotReadIsNotFound(): void {
		$placements = $this->createMock(originalClassName: SharedBlockPlacementsInterface::class);
		$placements->method('pagesOf')->willReturn(null);

		$response = $this->controller(mayEdit: true, placements: $placements)->pages(id: 'block-1');

		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'not_found'], actual: $response->getData());
	}//end testABlockTheEditorCannotReadIsNotFound()

	/**
	 * The controller over an editor decision.
	 *
	 * @param bool                           $mayEdit    Whether the caller may edit.
	 * @param SharedBlockPlacementsInterface $placements The placements.
	 *
	 * @return SharedBlockController
	 */
	private function controller(bool $mayEdit, SharedBlockPlacementsInterface $placements): SharedBlockController {
		$editor = $this->createMock(originalClassName: PageEditorServiceInterface::class);
		$editor->method('mayEdit')->willReturn($mayEdit);

		return new SharedBlockController(request: $this->createMock(originalClassName: IRequest::class), pageEditor: $editor, placements: $placements);
	}//end controller()
}//end class
