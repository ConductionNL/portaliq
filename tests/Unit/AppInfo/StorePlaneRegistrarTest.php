<?php

/**
 * Tests for the store plane's controller half (WOO-559).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\AppInfo
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

namespace OCA\Portaliq\Tests\Unit\AppInfo;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\AppInfo\StorePlaneRegistrar;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * A route without its controller is a dispatch-time 500, not a 404 — the
 * router matches `/api/store/items`, asks the app container for
 * `OCA\Portaliq\Controller\StoreController`, and finds nothing. The registrar
 * is what puts that name in the container, bound to OpenRegister's generic
 * store controller with `portaliq` as the calling app.
 *
 * @covers \OCA\Portaliq\AppInfo\StorePlaneRegistrar
 */
final class StorePlaneRegistrarTest extends TestCase {

	/**
	 * The class name Nextcloud's router derives from the `store#…` route names.
	 */
	private const ROUTED_CONTROLLER = 'OCA\\Portaliq\\Controller\\StoreController';

	/**
	 * A registration context that records every `registerService()` name.
	 *
	 * @param array<int, string> $recorded Receives the registered service names.
	 *
	 * @return IRegistrationContext
	 */
	private function recordingContext(array &$recorded): IRegistrationContext {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')
			->willReturnCallback(
				static function (string $name, callable $factory, bool $shared = true) use (&$recorded): void {
					$recorded[] = $name;
				}
			);

		return $context;
	}//end recordingContext()

	/**
	 * The routed controller name is bound in the app container.
	 *
	 * This is the assertion the whole fix rests on. The binding is made by
	 * OpenRegister's `Bootstrap::aliasStoreController()`, so it needs
	 * OpenRegister beside this checkout: CI installs it (`additional-apps` in
	 * code-quality.yml) and the docker dev instance has it. A bare checkout
	 * without it cannot exercise the binding and says so, rather than passing.
	 *
	 * @return void
	 */
	public function testBindsTheRoutedStoreControllerNameAtTheEngine(): void {
		$recorded = [];
		$context = $this->recordingContext($recorded);

		$bound = (new StorePlaneRegistrar())->register($context);

		if ($bound === false && class_exists('OCA\\OpenRegister\\AppHost\\Bootstrap') === false) {
			$this->markTestSkipped(
				'OpenRegister is not installed beside this checkout, so the engine alias cannot be '
				. 'exercised here. It is exercised in CI, which installs openregister.'
			);
		}

		$this->assertTrue($bound, 'The registrar reported that it did not bind the alias.');
		$this->assertContains(
			self::ROUTED_CONTROLLER,
			$recorded,
			sprintf(
				'%s was not registered in the app container. The router resolves the store#… routes to '
				. 'exactly this name; without the binding every store request 500s at dispatch. Registered: [%s]',
				self::ROUTED_CONTROLLER,
				implode(', ', $recorded)
			)
		);

	}//end testBindsTheRoutedStoreControllerNameAtTheEngine()

	/**
	 * The ADR-040 prelude asks for OpenRegister by its app id and survives its absence.
	 *
	 * The prelude has to run BEFORE the AppHost class is referenced, and a
	 * missing OpenRegister must degrade rather than abort `register()` — an
	 * exception here would take every listener registered after this call
	 * down with it (the doriath incident). Whether the alias is then bound
	 * depends on whether another app already pulled OpenRegister's autoloader
	 * into this process — precisely the masking ADR-040 describes — so only the
	 * contract that holds everywhere is asserted: the app manager was asked
	 * for `openregister`, the failure was swallowed, and a boolean came back.
	 *
	 * @return void
	 */
	public function testPreludeAsksForOpenRegisterAndSwallowsItsAbsence(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())
			->method('getAppPath')
			->with('openregister')
			->willThrowException(new AppPathNotFoundException('openregister is not installed'));

		$recorded = [];
		$context = $this->recordingContext($recorded);

		$result = (new StorePlaneRegistrar())->register($context, $appManager);

		$this->assertIsBool($result, 'register() must answer with a boolean, never throw, when OpenRegister is absent.');

	}//end testPreludeAsksForOpenRegisterAndSwallowsItsAbsence()

	/**
	 * The alias namespace is the one the router derives from this app's namespace.
	 *
	 * Nextcloud resolves `store#search` to `<app namespace>\Controller\StoreController`.
	 * The registrar passes that namespace to the engine as a string, so a
	 * namespace rename would leave the alias pointing at a name nothing asks
	 * for. Derived from the Application class rather than repeated, so the
	 * test fails on the rename instead of agreeing with the stale constant.
	 *
	 * @return void
	 */
	public function testAliasNamespaceMatchesTheRouterDerivedControllerNamespace(): void {
		$appNamespace = substr(Application::class, 0, (int)strrpos(Application::class, '\\AppInfo\\Application'));

		$this->assertNotSame('', $appNamespace, 'Could not derive the app namespace from Application::class.');
		$this->assertSame(
			$appNamespace . '\\Controller',
			StorePlaneRegistrar::CONTROLLER_NAMESPACE,
			'StorePlaneRegistrar::CONTROLLER_NAMESPACE must be the namespace the router prepends to route names.'
		);
		$this->assertSame(
			self::ROUTED_CONTROLLER,
			StorePlaneRegistrar::CONTROLLER_NAMESPACE . '\\StoreController',
			'The namespace plus the router\'s class suffix must spell the routed controller name.'
		);

	}//end testAliasNamespaceMatchesTheRouterDerivedControllerNamespace()

}//end class
