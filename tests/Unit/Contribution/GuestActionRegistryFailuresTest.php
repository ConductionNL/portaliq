<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\GuestActionRegistry;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A provider that cannot answer, answers with a non-array or throws never
 * yields a guest action; a throw is logged with the app and reason.
 */
#[CoversClass(GuestActionRegistry::class)]
class GuestActionRegistryFailuresTest extends TestCase {

	private function guestProvider(): object {
		return new class {

			public function getAudiences(): array {
				return ['guest'];
			}

			public function getContribution(array $subject): array {
				return [];
			}
		};
	}//end guestProvider()

	private function registry(object $provider, mixed $contribution, ?LoggerInterface $logger = null, int $normalised = 0): GuestActionRegistry {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['portaliq']);
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->with('portaliq')->willReturn($provider);
		if ($contribution instanceof \Throwable) {
			$locator->method('contributionOf')->willThrowException($contribution);
		} else {
			$locator->method('contributionOf')
				->with($provider, ['audience' => 'guest', 'trust' => 'low'])
				->willReturn($contribution);
		}

		$normaliser = $this->createMock(PortalManifestNormaliser::class);
		$normaliser->expects($this->exactly($normalised))->method('normalise')->willReturnArgument(0);

		return new GuestActionRegistry($apps, $locator, $logger ?? $this->createMock(LoggerInterface::class), $normaliser);
	}//end registry()

	public function testANonArrayContributionHasNoGuestAction(): void {
		$this->assertNull($this->registry($this->guestProvider(), 'oops')->guestAction('portaliq', 'withdraw'));
	}//end testANonArrayContributionHasNoGuestAction()

	public function testAThrowingProviderIsLoggedAndHasNoGuestAction(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Portaliq: guest contribution failed',
			['app' => 'portaliq', 'reason' => 'provider down']
		);

		$registry = $this->registry($this->guestProvider(), new RuntimeException('provider down'), $logger);
		$this->assertNull($registry->guestAction('portaliq', 'withdraw'));
	}//end testAThrowingProviderIsLoggedAndHasNoGuestAction()

	public function testAProviderWithoutGetContributionIsNeverAsked(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['guest'];
			}
		};

		$this->assertNull($this->registry($provider, ['actions' => [['id' => 'withdraw', 'guest' => true]]])->guestAction('portaliq', 'withdraw'));
	}//end testAProviderWithoutGetContributionIsNeverAsked()

	public function testTheNormalisedContributionCarriesTheAppId(): void {
		$action = ['id' => 'withdraw', 'guest' => true];
		$registry = $this->registry($this->guestProvider(), ['actions' => [$action]], null, 1);
		$this->assertSame($action, $registry->guestAction('portaliq', 'withdraw'));
	}//end testTheNormalisedContributionCarriesTheAppId()
}//end class
