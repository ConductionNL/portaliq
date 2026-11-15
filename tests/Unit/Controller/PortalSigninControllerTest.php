<?php

/**
 * PortalSigninController: a portal's sign-in settings are read and saved by
 * that portal's administrators only; anyone else gets the unknown-portal 404
 * and nothing is saved (operate-portals-per-organisation REQ-OPO-005).
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalSigninController;
use OCA\Portaliq\Service\Signin\PortalSigninSettings;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(PortalSigninController::class)]
class PortalSigninControllerTest extends TestCase {

	/**
	 * The portal the slug finds.
	 *
	 * @var array<string, mixed>
	 */
	private const PORTAL = ['slug' => 'zeist', 'adminGroups' => ['zeist-beheer']];

	/**
	 * The settings double.
	 *
	 * @var PortalSigninSettings&MockObject
	 */
	private $settings;

	/**
	 * A controller whose scope answers `$may` for the portal the slug finds.
	 *
	 * @param bool $may What the scope answers.
	 *
	 * @return PortalSigninController
	 */
	private function controller(bool $may): PortalSigninController {
		$this->settings = $this->createMock(PortalSigninSettings::class);
		$this->settings->method('portalBySlug')->willReturnCallback(
			static fn (string $slug): ?array => (['zeist' => self::PORTAL][$slug] ?? null)
		);

		$scope = $this->createMock(PortalAdminScope::class);
		$scope->method('mayAdminister')->with(self::PORTAL)->willReturn($may);

		return new PortalSigninController($this->createMock(IRequest::class), $this->settings, $scope);
	}//end controller()

	/**
	 * The portal's administrator reads and saves its settings.
	 *
	 * @return void
	 */
	public function testThePortalsAdministratorReadsAndSaves(): void {
		$controller = $this->controller(may: true);
		$this->settings->method('view')->with(self::PORTAL)->willReturn(['routes' => ['digid' => 'oidc']]);
		$this->settings->expects($this->once())
			->method('save')
			->with(self::PORTAL, ['digid' => 'broker'], ['startUrl' => 'https://b.example/start'], '')
			->willReturn(['routes' => ['digid' => 'broker']]);

		$read = $controller->index(slug: 'zeist');
		$saved = $controller->update(slug: 'zeist', routes: ['digid' => 'broker'], broker: ['startUrl' => 'https://b.example/start']);

		$this->assertSame(Http::STATUS_OK, $read->getStatus());
		$this->assertSame(['routes' => ['digid' => 'oidc']], $read->getData());
		$this->assertSame(Http::STATUS_OK, $saved->getStatus());
	}//end testThePortalsAdministratorReadsAndSaves()

	/**
	 * Anyone else gets the unknown-portal 404, and nothing is read or saved.
	 *
	 * @return void
	 */
	public function testAnyoneElseGetsTheUnknownPortal404(): void {
		$controller = $this->controller(may: false);
		$this->settings->expects($this->never())->method('view');
		$this->settings->expects($this->never())->method('save');

		$read = $controller->index(slug: 'zeist');
		$saved = $controller->update(slug: 'zeist', routes: ['digid' => 'broker']);

		$this->assertSame(Http::STATUS_NOT_FOUND, $read->getStatus());
		$this->assertSame(['error' => 'portal_not_found'], $read->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $saved->getStatus());
		$this->assertSame(['error' => 'portal_not_found'], $saved->getData());
		$this->assertSame($read->getData(), $controller->index(slug: 'nowhere')->getData(), 'the same answer as an unknown portal');
	}//end testAnyoneElseGetsTheUnknownPortal404()

	/**
	 * Both routes are open to signed-in users and decided by the scope; neither is public.
	 *
	 * @return void
	 */
	public function testBothRoutesAreForThePortalsAdministrators(): void {
		foreach (['index', 'update'] as $method) {
			$names = array_map(
				static fn ($attribute): string => $attribute->getName(),
				(new ReflectionMethod(PortalSigninController::class, $method))->getAttributes()
			);
			$this->assertContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $names);
			$this->assertNotContains('OCP\AppFramework\Http\Attribute\PublicPage', $names);
		}
	}//end testBothRoutesAreForThePortalsAdministrators()
}//end class
