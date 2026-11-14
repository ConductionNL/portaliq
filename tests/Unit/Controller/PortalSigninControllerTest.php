<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalSigninController;
use OCA\Portaliq\Service\Signin\PortalSigninSettings;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The sign-in settings screen: an unknown portal is a 404, and each save
 * error maps to its own status.
 *
 * @covers \OCA\Portaliq\Controller\PortalSigninController
 */
class PortalSigninControllerTest extends TestCase {

	private const PORTAL = ['id' => 'p-1', 'slug' => 'zuid'];

	public function testIndexAnswersTheViewOfTheNamedPortal(): void {
		$settings = $this->createMock(PortalSigninSettings::class);
		$settings->expects($this->once())->method('portalBySlug')->with('zuid')->willReturn(self::PORTAL);
		$settings->expects($this->once())->method('view')->with(self::PORTAL)->willReturn(['routes' => ['digid']]);

		$response = $this->controller(settings: $settings)->index(slug: 'zuid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['routes' => ['digid']], $response->getData());
	}//end testIndexAnswersTheViewOfTheNamedPortal()

	public function testIndexIsNotFoundForAnUnknownPortalOrOneWithoutAView(): void {
		$settings = $this->createMock(PortalSigninSettings::class);
		$settings->method('portalBySlug')->willReturnCallback(static fn (string $slug): ?array => $slug === 'zuid' ? self::PORTAL : null);
		$settings->method('view')->willReturn(null);
		$controller = $this->controller(settings: $settings);

		foreach (['zuid', 'nergens'] as $slug) {
			$response = $controller->index(slug: $slug);
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $slug);
			$this->assertSame(['error' => 'portal_not_found'], $response->getData(), $slug);
		}
	}//end testIndexIsNotFoundForAnUnknownPortalOrOneWithoutAView()

	public function testUpdateOfAnUnknownPortalSavesNothing(): void {
		$settings = $this->createMock(PortalSigninSettings::class);
		$settings->method('portalBySlug')->willReturn(null);
		$settings->expects($this->never())->method('save');

		$response = $this->controller(settings: $settings)->update(slug: 'nergens', routes: ['digid']);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'portal_not_found'], $response->getData());
	}//end testUpdateOfAnUnknownPortalSavesNothing()

	/**
	 * Each save outcome and the status it is answered with.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int}>
	 */
	public static function outcomes(): array {
		return [
			'saved'             => [['routes' => ['digid']], Http::STATUS_OK],
			'no organisation'   => [['error' => 'no_organisation'], Http::STATUS_NOT_FOUND],
			'broker incomplete' => [['error' => 'broker_incomplete'], Http::STATUS_UNPROCESSABLE_ENTITY],
			'anything else'     => [['error' => 'broker_unreachable'], Http::STATUS_BAD_GATEWAY],
		];
	}//end outcomes()

	/**
	 * @dataProvider outcomes
	 *
	 * @param array<string, mixed> $result The save's answer.
	 * @param int                  $status The expected status.
	 */
	public function testUpdatePassesTheFormOnAndMapsTheOutcomeToAStatus(array $result, int $status): void {
		$settings = $this->createMock(PortalSigninSettings::class);
		$settings->method('portalBySlug')->willReturn(self::PORTAL);
		$settings->expects($this->once())
			->method('save')
			->with(self::PORTAL, ['digid', 'eherkenning'], ['url' => 'https://broker.example'], 's3cret')
			->willReturn($result);

		$response = $this->controller(settings: $settings)->update(slug: 'zuid', routes: ['digid', 'eherkenning'], broker: ['url' => 'https://broker.example'], secret: 's3cret');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}//end testUpdatePassesTheFormOnAndMapsTheOutcomeToAStatus()

	private function controller(PortalSigninSettings $settings): PortalSigninController {
		return new PortalSigninController(request: $this->createMock(IRequest::class), settings: $settings);
	}//end controller()
}//end class
