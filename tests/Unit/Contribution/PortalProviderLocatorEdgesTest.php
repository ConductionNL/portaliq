<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A provider without `getContribution()` contributes nothing, and a
 * container entry that is not an object is no provider.
 */
#[CoversClass(PortalProviderLocator::class)]
class PortalProviderLocatorEdgesTest extends TestCase {

	private function locator(mixed $entry): PortalProviderLocator {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppInfo')->willReturn(['namespace' => ' Portaliq ']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\Portaliq\\Portal\\PortalContributionProvider')->willReturn($entry);

		return new PortalProviderLocator($apps, $container, $this->createMock(LoggerInterface::class));
	}//end locator()

	public function testAProviderWithoutGetContributionAnswersNull(): void {
		$this->assertNull($this->locator(null)->contributionOf(new \stdClass(), ['audience' => 'citizen']));
	}//end testAProviderWithoutGetContributionAnswersNull()

	public function testWithoutALanguageTheProviderIsAskedDirectly(): void {
		$provider = new class {

			public function getContribution(array $subject): array {
				return ['asked' => $subject['audience']];
			}
		};

		$this->assertSame(['asked' => 'parent'], $this->locator(null)->contributionOf($provider, ['audience' => 'parent']));
	}//end testWithoutALanguageTheProviderIsAskedDirectly()

	public function testANonObjectContainerEntryIsNoProvider(): void {
		$this->assertNull($this->locator('a string')->locate('portaliq'));
	}//end testANonObjectContainerEntryIsNoProvider()

	public function testAnObjectEntryIsTheProvider(): void {
		$provider = new \stdClass();
		$this->assertSame($provider, $this->locator($provider)->locate('portaliq'));
	}//end testAnObjectEntryIsTheProvider()
}//end class
