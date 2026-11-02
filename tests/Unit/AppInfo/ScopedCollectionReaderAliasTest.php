<?php

/**
 * ScopedCollectionReaderAliasTest
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\AppInfo;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * The container hands every class that asks for the scoped collection reader
 * the portal object reader, as the app's own `register()` binds it.
 *
 * No coverage metadata on purpose: the test runs the whole of
 * `Application::register()`, which touches every registrar the app has.
 */
final class ScopedCollectionReaderAliasTest extends TestCase {

	/**
	 * The app binds the scoped collection reader to the portal object reader.
	 *
	 * @return void
	 */
	public function testTheAppBindsTheScopedReaderToThePortalObjectReader(): void {
		$aliases = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerServiceAlias')
			->willReturnCallback(
				callback: static function (string $alias, string $target) use (&$aliases): void {
					$aliases[$alias] = $target;
				}
			);

		$app = (new ReflectionClass(objectOrClass: Application::class))->newInstanceWithoutConstructor();
		try {
			$app->register(context: $context);
		} catch (Throwable $e) {
			$this->fail(message: 'Application::register() did not run to its end: ' . $e->getMessage());
		}

		$this->assertSame(expected: PortalObjectReader::class, actual: $aliases[ScopedCollectionReaderInterface::class] ?? null);
	}//end testTheAppBindsTheScopedReaderToThePortalObjectReader()
}//end class
