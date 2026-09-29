<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalThemeController;
use OCA\Portaliq\Service\Theme\PortalThemeChoice;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * nldesign-theme-integration: the theme picker's routes answer 404 for an
 * unknown portal, 422 for a refused set with the reason, 502 for a failed
 * write, and carry no opt-out attribute, so Nextcloud keeps them admin-only.
 *
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */
class PortalThemeControllerTest extends TestCase {

	/**
	 * The choice double.
	 *
	 * @var PortalThemeChoice&MockObject
	 */
	private $choice;

	public function testAnUnknownPortalIs404(): void {
		$controller = $this->controller(portal: null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->index(slug: 'nope')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->update(slug: 'nope', theme: 'vng')->getStatus());
	}//end testAnUnknownPortalIs404()

	public function testTheListIsServedForAKnownPortal(): void {
		$controller = $this->controller(portal: ['slug' => 'gemeente']);
		$this->choice->method('listFor')->willReturn(['current' => 'vng', 'currentResolves' => true, 'sets' => []]);

		$response = $controller->index(slug: 'gemeente');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('vng', $response->getData()['current']);
	}//end testTheListIsServedForAKnownPortal()

	public function testARefusedSetIs422WithTheReason(): void {
		$controller = $this->controller(portal: ['slug' => 'gemeente']);
		$this->choice->method('choose')->willReturn(['error' => 'contrast', 'verdict' => ['findings' => [['token' => '--x']]]]);

		$response = $controller->update(slug: 'gemeente', theme: 'faint');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('contrast', $response->getData()['error']);
	}//end testARefusedSetIs422WithTheReason()

	public function testTheConfirmationIsPassedOn(): void {
		$controller = $this->controller(portal: ['slug' => 'gemeente']);
		$this->choice->expects($this->once())
			->method('choose')
			->with($this->anything(), $this->equalTo('faint'), $this->isTrue())
			->willReturn(['portal' => ['slug' => 'gemeente', 'theme' => 'faint']]);
		$this->choice->method('listFor')->willReturn(['current' => 'faint', 'currentResolves' => true, 'sets' => []]);

		$this->assertSame(Http::STATUS_OK, $controller->update(slug: 'gemeente', theme: 'faint', acceptFindings: true)->getStatus());
	}//end testTheConfirmationIsPassedOn()

	public function testAFailedWriteIs502(): void {
		$controller = $this->controller(portal: ['slug' => 'gemeente']);
		$this->choice->method('choose')->willReturn(['error' => 'save_failed']);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $controller->update(slug: 'gemeente', theme: 'vng')->getStatus());
	}//end testAFailedWriteIs502()

	public function testBothRoutesStayAdminOnly(): void {
		foreach (['index', 'update'] as $method) {
			$attributes = array_map(
				static fn ($attribute): string => $attribute->getName(),
				(new ReflectionMethod(PortalThemeController::class, $method))->getAttributes()
			);
			$this->assertSame([], array_values(array_filter($attributes, static fn (string $name): bool => str_contains($name, 'NoAdminRequired') || str_contains($name, 'PublicPage'))));
		}
	}//end testBothRoutesStayAdminOnly()

	/**
	 * The controller over a choice double.
	 *
	 * @param array<string, mixed>|null $portal The portal the slug finds.
	 *
	 * @return PortalThemeController
	 */
	private function controller(?array $portal): PortalThemeController {
		$this->choice = $this->getMockBuilder(PortalThemeChoice::class)
			->disableOriginalConstructor()
			->onlyMethods(['portalBySlug', 'listFor', 'choose'])
			->getMock();
		$this->choice->method('portalBySlug')->willReturn($portal);

		return new PortalThemeController($this->createMock(IRequest::class), $this->choice);
	}//end controller()

}//end class
