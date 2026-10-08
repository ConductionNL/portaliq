<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Portal\PortalContributionProvider;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the FQCN derivation that decides whether an app contributes at all.
 *
 * This is the step that failed silently for two installed apps: it guessed the
 * namespace as `ucfirst($appId)`, which is wrong for every app id that is not
 * one lowercase word, and `class_exists()` reports an unloadable class as
 * `false` rather than raising. The app was therefore skipped with no error, no
 * log line and no failing test anywhere.
 *
 * `OCA\Portaliq\Portal\PortalContributionProvider` is the one provider class
 * that really exists in this app, so these tests point differently-shaped app
 * ids at it to prove which derivation finds it.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */
class PortalProviderLocatorTest extends TestCase {

	private const PROVIDER_FQCN = 'OCA\\Portaliq\\Portal\\PortalContributionProvider';

	/**
	 * The declared `<namespace>` wins over the ucfirst guess.
	 */
	public function testLocatesAProviderWhoseNamespaceIsNotUcfirstOfTheAppId(): void {
		$locator = new PortalProviderLocator(
			$this->appManager(['zaakafhandelapp' => 'Portaliq']),
			$this->container(),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertNotNull($locator->locate('zaakafhandelapp'));

	}//end testLocatesAProviderWhoseNamespaceIsNotUcfirstOfTheAppId()

	/**
	 * An app declaring no `<namespace>` still resolves through `ucfirst()`.
	 */
	public function testFallsBackToUcfirstWhenNoNamespaceIsDeclared(): void {
		$locator = new PortalProviderLocator(
			$this->appManager([]),
			$this->container(),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertNotNull($locator->locate('portaliq'));

	}//end testFallsBackToUcfirstWhenNoNamespaceIsDeclared()

	/**
	 * An app shipping no provider class yields null rather than raising.
	 */
	public function testReturnsNullForAnAppThatShipsNoProvider(): void {
		$locator = new PortalProviderLocator(
			$this->appManager([]),
			$this->container(),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertNull($locator->locate('someotherapp'));

	}//end testReturnsNullForAnAppThatShipsNoProvider()

	/**
	 * A provider class that exists but cannot be constructed yields null.
	 */
	public function testReturnsNullWhenTheContainerCannotConstructTheProvider(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('boom'));

		$locator = new PortalProviderLocator(
			$this->appManager([]),
			$container,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertNull($locator->locate('portaliq'));

	}//end testReturnsNullWhenTheContainerCannotConstructTheProvider()

	/**
	 * @param array<string, string> $namespaces App id => `info.xml` `<namespace>`.
	 *
	 * @return IAppManager
	 */
	private function appManager(array $namespaces): IAppManager {
		$mock = $this->createMock(IAppManager::class);
		$mock->method('getAppInfo')->willReturnCallback(
			static function (string $appId) use ($namespaces): ?array {
				if (array_key_exists($appId, $namespaces) === false) {
					return ['id' => $appId];
				}

				return ['id' => $appId, 'namespace' => $namespaces[$appId]];
			}
		);
		return $mock;
	}//end appManager()

	/**
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$provider = $this->createMock(PortalContributionProvider::class);
		$mock = $this->createMock(ContainerInterface::class);
		$mock->method('get')->willReturnCallback(
			function (string $id) use ($provider): object {
				if ($id === self::PROVIDER_FQCN) {
					return $provider;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);
		return $mock;
	}//end container()

}//end class
