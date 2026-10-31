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

use OCA\Portaliq\Controller\ActionSettingsController;
use OCA\Portaliq\Controller\MetricsController;
use OCA\Portaliq\Controller\PortalHomePageController;
use OCA\Portaliq\Controller\SessionAdminController;
use OCA\Portaliq\Controller\SettingsController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * These five routed methods are admin-only ON PURPOSE.
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
 * @covers \OCA\Portaliq\Controller\PortalHomePageController
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
	 * The URL prefixes of the admin settings routes (REQ-ORA-007): the app
	 * settings with the action grants, session revocation, and a portal's
	 * settings, sign-in and theme.
	 */
	private const ADMIN_SETTINGS_PREFIXES = [
		'/api/settings',
		'/api/session-admin/',
		'/api/portals/{slug}/',
	];

	/**
	 * Admin settings routes open to every signed-in user, with the reason.
	 */
	private const SHARED_READS = [
		'settings#index' => 'answers non-admins without the register, the editor groups and the geography settings',
	];

	/**
	 * Calls that refuse a user inside a method: an action grant, an
	 * administrator check, or the per-portal admin scope.
	 */
	private const GUARD_CALLS = [
		'requireAction(',
		'->can(',
		'isAdmin(',
		'mayAdminister(',
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
			'sessionadmin#revokeAccount' => [SessionAdminController::class, 'revokeAccount'],
			'sessionadmin#revokeSession' => [SessionAdminController::class, 'revokeSession'],
			'actionsettings#byGroup' => [ActionSettingsController::class, 'byGroup'],
			'settings#load' => [SettingsController::class, 'load'],
			'actionsettings#index' => [ActionSettingsController::class, 'index'],
			'actionsettings#update' => [ActionSettingsController::class, 'update'],
			'settings#update' => [SettingsController::class, 'update'],
			'settings#create' => [SettingsController::class, 'create'],
			'portalhomepage#index' => [PortalHomePageController::class, 'index'],
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
		foreach ((new ReflectionClass(\OCA\Portaliq\Controller\BundledSiteController::class))
			->getMethod('serve')->getAttributes() as $attribute) {
			$parts = explode('\\', $attribute->getName());
			$names[] = (string)end($parts);
		}

		$this->assertContains(
			'PublicPage',
			$names,
			'BundledSiteController::serve() is #[PublicPage]; if this fails the attribute reflection is broken, '
			. 'and every admin-only assertion in this file is vacuous.'
		);
	}//end testTheCheckSeesAWideningAttributeWhenOneIsPresent()


	/**
	 * Every admin settings route refuses a page editor who is not an
	 * administrator: it is admin-only (Nextcloud answers 403 before the
	 * method runs), or it opts out and the method checks an action grant,
	 * an administrator or the per-portal admin scope itself. A page editor
	 * holds none of those (PageEditorServiceTest::testAPageEditorHoldsNoAction).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-content-rights-and-settings-rights-stay-apart-req-ora-007
	 */
	public function testEveryAdminSettingsRouteRefusesAPageEditor(): void {
		$listed = self::adminOnlyMethodProvider();
		$routes = $this->adminSettingsRoutes();
		$this->assertContains('portalsignin#update', array_keys($routes), 'The census must see the sign-in settings, or it checks nothing.');

		foreach ($routes as $name => [$class, $method]) {
			if (isset(self::SHARED_READS[$name]) === true) {
				continue;
			}

			if ($this->widens(class: $class, method: $method) === false) {
				continue;
			}

			$this->assertArrayNotHasKey($name, $listed, $name . ' is listed admin-only but opts out.');
			$this->assertTrue(
				$this->guards(class: $class, method: $method),
				$name . ' opts out of the admin check and checks no action, administrator or portal scope itself.'
			);
		}
	}//end testEveryAdminSettingsRouteRefusesAPageEditor()


	/**
	 * Positive control on the census: a method that opts out and checks
	 * nothing is seen as unguarded.
	 *
	 * @return void
	 */
	public function testTheCensusSeesAnUnguardedMethod(): void {
		$this->assertTrue($this->widens(class: \OCA\Portaliq\Controller\BundledSiteController::class, method: 'serve'));
		$this->assertFalse($this->guards(class: \OCA\Portaliq\Controller\BundledSiteController::class, method: 'serve'));
	}//end testTheCensusSeesAnUnguardedMethod()


	/**
	 * The routes under the admin settings prefixes, by lower-cased name.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	private function adminSettingsRoutes(): array {
		$config = require __DIR__ . '/../../../appinfo/routes.php';
		$routes = [];
		foreach ($config['routes'] as $route) {
			$url = (string)$route['url'];
			$matches = array_filter(
				self::ADMIN_SETTINGS_PREFIXES,
				static fn (string $prefix): bool => str_starts_with($url, $prefix)
			);
			if (count($matches) === 0) {
				continue;
			}

			[$controller, $method] = explode('#', (string)$route['name']);
			$class = 'OCA\\Portaliq\\Controller\\' . ucfirst($controller) . 'Controller';
			$routes[strtolower($controller) . '#' . $method] = [$class, $method];
		}

		return $routes;
	}//end adminSettingsRoutes()


	/**
	 * Whether a method opts out of Nextcloud's admin check.
	 *
	 * @param class-string $class  The controller class.
	 * @param string       $method The method name.
	 *
	 * @return bool
	 */
	private function widens(string $class, string $method): bool {
		$reflection = new ReflectionMethod($class, $method);
		foreach ($reflection->getAttributes() as $attribute) {
			$parts = explode('\\', $attribute->getName());
			if (in_array((string)end($parts), self::WIDENING_ATTRIBUTES, true) === true) {
				return true;
			}
		}

		return preg_match('/^\s*\*\s*@(NoAdminRequired|PublicPage)\b/m', (string)$reflection->getDocComment()) === 1;
	}//end widens()


	/**
	 * Whether a method, or a method of its class it calls, makes a guard call.
	 *
	 * @param class-string $class  The controller class.
	 * @param string       $method The method name.
	 *
	 * @return bool
	 */
	private function guards(string $class, string $method): bool {
		$body = $this->bodyOf(reflection: new ReflectionMethod($class, $method));
		preg_match_all('/\$this->(\w+)\(/', $body, $calls);
		foreach (array_unique($calls[1]) as $called) {
			if (method_exists($class, $called) === true) {
				$body .= $this->bodyOf(reflection: new ReflectionMethod($class, $called));
			}
		}

		foreach (self::GUARD_CALLS as $call) {
			if (str_contains($body, $call) === true) {
				return true;
			}
		}

		return false;
	}//end guards()


	/**
	 * The source lines of a method.
	 *
	 * @param ReflectionMethod $reflection The method.
	 *
	 * @return string
	 */
	private function bodyOf(ReflectionMethod $reflection): string {
		$lines = file((string)$reflection->getFileName());
		$start = ((int)$reflection->getStartLine() - 1);

		return implode('', array_slice($lines, $start, ((int)$reflection->getEndLine() - $start)));
	}//end bodyOf()
}//end class
