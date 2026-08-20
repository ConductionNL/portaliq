<?php

/**
 * Admin-only posture regression guard.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MetricsController;
use OCA\Portaliq\Controller\SessionAdminController;
use OCA\Portaliq\Controller\SettingsController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * These four routed methods are admin-only ON PURPOSE.
 *
 * Nextcloud expresses "instance admin required" as the ABSENCE of an opt-out
 * attribute — there is no positive `#[AdminRequired]` to assert. That makes the
 * posture invisible: an admin-only endpoint and one where the attribute was
 * forgotten look identical in the source, and ADDING an opt-out is a one-line,
 * easy-to-review-through change that silently opens the endpoint to every
 * authenticated user.
 *
 * This test makes the absence explicit and enforced. It fails the moment any of
 * these methods gains `NoAdminRequired` or `PublicPage` — in attribute OR
 * docblock form — which is exactly the regression that would otherwise ship
 * unnoticed.
 *
 * `AuthorizedAdminSetting` is asserted absent too, and deliberately: it is not
 * a synonym for admin-only. It additionally admits DELEGATED settings admins,
 * so adopting it here would widen access rather than document it.
 *
 * @covers \OCA\Portaliq\Controller\MetricsController
 * @covers \OCA\Portaliq\Controller\SessionAdminController
 * @covers \OCA\Portaliq\Controller\SettingsController
 */
class AdminOnlyPostureTest extends TestCase {

	/**
	 * Attributes whose presence would WIDEN access beyond instance admin.
	 */
	private const WIDENING_ATTRIBUTES = [
		'NoAdminRequired',
		'PublicPage',
		'AuthorizedAdminSetting',
	];

	/**
	 * The routed methods that must stay admin-only.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function adminOnlyMethodProvider(): array {
		return [
			'metrics#index' => [MetricsController::class, 'index'],
			'sessionadmin#revokeOrganisation' => [SessionAdminController::class, 'revokeOrganisation'],
			'settings#update' => [SettingsController::class, 'update'],
			'settings#create' => [SettingsController::class, 'create'],
		];
	}//end adminOnlyMethodProvider()

	/**
	 * No opt-out ATTRIBUTE is present, so Nextcloud's admin default applies.
	 *
	 * @param class-string $class The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyMethodProvider
	 */
	public function testMethodDeclaresNoAccessWideningAttribute(string $class, string $method): void {
		$reflection = new ReflectionMethod($class, $method);

		$names = array_map(
			static function (\ReflectionAttribute $attribute): string {
				$parts = explode('\\', $attribute->getName());
				return (string)end($parts);
			},
			$reflection->getAttributes()
		);

		foreach (self::WIDENING_ATTRIBUTES as $widening) {
			$this->assertNotContains(
				$widening,
				$names,
				$class . '::' . $method . '() is admin-only by design; #[' . $widening . '] would widen it.'
			);
		}
	}//end testMethodDeclaresNoAccessWideningAttribute()

	/**
	 * No opt-out DOCBLOCK annotation either — Nextcloud honours `@PublicPage`
	 * and `@NoAdminRequired` in a docblock just as it honours the attribute, so
	 * checking only attributes would miss half the regression.
	 *
	 * @param class-string $class The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyMethodProvider
	 */
	public function testMethodDeclaresNoAccessWideningAnnotation(string $class, string $method): void {
		$doc = (string)(new ReflectionMethod($class, $method))->getDocComment();

		foreach (['NoAdminRequired', 'PublicPage'] as $widening) {
			$this->assertDoesNotMatchRegularExpression(
				'/^\s*\*\s*@' . $widening . '\b/m',
				$doc,
				$class . '::' . $method . '() is admin-only by design; @' . $widening . ' would widen it.'
			);
		}
	}//end testMethodDeclaresNoAccessWideningAnnotation()

	/**
	 * The posture is DECLARED, not merely implied: each method carries the
	 * `@auth admin-only <reason>` tag at docblock-tag position with a reason of
	 * at least 20 characters.
	 *
	 * Without this, "admin-only on purpose" and "attribute forgotten" remain
	 * indistinguishable to a reader and to the route-auth gate.
	 *
	 * @param class-string $class The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyMethodProvider
	 */
	public function testMethodDeclaresItsAdminOnlyPosture(string $class, string $method): void {
		$doc = (string)(new ReflectionMethod($class, $method))->getDocComment();

		$this->assertMatchesRegularExpression(
			'/^\s*\*\s*@auth\s+admin-only\s+.{20,}/m',
			$doc,
			$class . '::' . $method . '() must declare @auth admin-only with a reason of 20+ characters.'
		);
	}//end testMethodDeclaresItsAdminOnlyPosture()

	/**
	 * Positive control on the assertions above: a method that IS deliberately
	 * open must be seen as open. Without this, a bug that made the reflection
	 * always report "no widening attribute" would leave every assertion above
	 * green while checking nothing.
	 *
	 * @return void
	 */
	public function testTheCheckSeesAWideningAttributeWhenOneIsPresent(): void {
		$names = [];
		foreach ((new ReflectionClass(\OCA\Portaliq\Controller\WooController::class))
			->getMethod('serve')->getAttributes() as $attribute) {
			$parts = explode('\\', $attribute->getName());
			$names[] = (string)end($parts);
		}

		$this->assertContains(
			'PublicPage',
			$names,
			'WooController::serve() is #[PublicPage]; if this fails the attribute reflection is broken, '
			. 'and every admin-only assertion in this file is vacuous.'
		);
	}//end testTheCheckSeesAWideningAttributeWhenOneIsPresent()
}//end class
