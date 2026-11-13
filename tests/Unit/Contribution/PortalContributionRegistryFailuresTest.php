<?php

/**
 * Unit tests for how the contribution registry survives failing providers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A provider that throws or answers something other than a manifest drops out
 * with an error in the log, and the other apps are still aggregated.
 */
#[CoversClass(PortalContributionRegistry::class)]
#[UsesClass(\OCA\Portaliq\Contribution\ActionConfigNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\ActionOptionsNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\AggregateRun::class)]
#[UsesClass(\OCA\Portaliq\Contribution\AnonymousContributions::class)]
#[UsesClass(\OCA\Portaliq\Contribution\AttachedActionResolver::class)]
#[UsesClass(\OCA\Portaliq\Contribution\CollectionConfigNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\CollectionSchemaLabels::class)]
#[UsesClass(\OCA\Portaliq\Contribution\HiddenPagesReader::class)]
#[UsesClass(\OCA\Portaliq\Contribution\InboxReplyConfigNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\ItemListConfigNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\NotificationRuleNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\PageChoice::class)]
#[UsesClass(\OCA\Portaliq\Contribution\PortalManifestNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\PortalPageResolver::class)]
#[UsesClass(\OCA\Portaliq\Contribution\ProviderAudiences::class)]
#[UsesClass(\OCA\Portaliq\Contribution\PublicRecordsNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\RowActionResolver::class)]
#[UsesClass(\OCA\Portaliq\Contribution\RowWhenNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Contribution\TimedTaskConfigNormaliser::class)]
#[UsesClass(\OCA\Portaliq\Service\Session\SessionTrust::class)]
#[UsesClass(\OCA\Portaliq\Contribution\StartTileCollector::class)]
class PortalContributionRegistryFailuresTest extends TestCase {

	/**
	 * The app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * The provider locator.
	 *
	 * @var PortalProviderLocator&MockObject
	 */
	private PortalProviderLocator&MockObject $locator;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->locator    = $this->createMock(PortalProviderLocator::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A throwing provider is logged, a non-array answer is skipped, and the working app stays.
	 *
	 * @return void
	 */
	public function testFailingProvidersDropOutOfTheAggregate(): void {
		$throwing = self::provider(audiences: ['citizen']);
		$scalar   = self::provider(audiences: ['citizen']);
		$working  = self::provider(audiences: ['citizen']);
		$this->apps(['a' => $throwing, 'b' => $scalar, 'c' => null, 'd' => $working]);
		$this->locator->method('contributionOf')->willReturnCallback(
			static function (object $provider) use ($throwing, $scalar): mixed {
				if ($provider === $throwing) {
					throw new RuntimeException('boom');
				}

				return $provider === $scalar ? 'not a manifest' : ['label' => 'Working'];
			}
		);
		$this->logger->expects($this->once())->method('error')->with('Portaliq: contribution provider failed', ['app' => 'a', 'reason' => 'boom']);

		$aggregate = $this->registry()->aggregateFor(subject: ['audience' => 'citizen', 'trust' => 'high']);

		$this->assertSame(['d'], array_column($aggregate['contributions'], 'app'));
	}//end testFailingProvidersDropOutOfTheAggregate()

	/**
	 * A manifest the normaliser cannot read is logged and kept as provided.
	 *
	 * @return void
	 */
	public function testANormaliserFailureKeepsTheManifest(): void {
		$this->apps(['a' => self::provider(audiences: ['citizen'])]);
		$this->locator->method('contributionOf')->willReturn(['label' => 'Raw']);
		$normaliser = $this->createMock(PortalManifestNormaliser::class);
		$normaliser->method('normalise')->willThrowException(new RuntimeException('bad manifest'));
		$this->logger->expects($this->once())->method('error')->with('Portaliq: manifest normalisation failed', ['app' => 'a', 'reason' => 'bad manifest']);

		$aggregate = $this->registry(normaliser: $normaliser)->aggregateFor(subject: ['audience' => 'citizen', 'trust' => 'high']);

		$this->assertCount(1, $aggregate['contributions']);
		$this->assertSame('Raw', $aggregate['contributions'][0]['label']);
		$this->assertSame('a', $aggregate['contributions'][0]['app']);
		$this->assertArrayNotHasKey('servedAudiences', $aggregate['contributions'][0]);
	}//end testANormaliserFailureKeepsTheManifest()

	/**
	 * Start tiles skip a non-contributing provider, a non-array answer and a throwing audience.
	 *
	 * @return void
	 */
	public function testStartTilesSurviveFailingProviders(): void {
		$this->apps(['a' => self::provider(audiences: ['citizen', 'business']), 'b' => new \stdClass(), 'c' => null]);
		$this->locator->method('contributionOf')->willReturnCallback(
			static function (object $provider, array $subject): mixed {
				if ($subject['audience'] === 'citizen') {
					throw new RuntimeException('down');
				}

				return null;
			}
		);
		$this->logger->expects($this->once())->method('error')->with('Portaliq: contribution provider failed (start tiles)', ['app' => 'a', 'reason' => 'down']);

		$this->assertSame([], $this->registry()->startTiles());
	}//end testStartTilesSurviveFailingProviders()

	/**
	 * The served audiences are every provider's audiences, each once.
	 *
	 * @return void
	 */
	public function testServedAudiencesAreUnique(): void {
		$this->apps(['a' => self::provider(audiences: ['citizen', 'business']), 'b' => null, 'c' => self::provider(audiences: ['business', 'supplier'])]);

		$this->assertSame(['citizen', 'business', 'supplier'], $this->registry()->servedAudiences());
	}//end testServedAudiencesAreUnique()

	/**
	 * An app whose provider has no `getContribution()` adds nothing anonymously.
	 *
	 * @return void
	 */
	public function testAnonymousSkipsNonContributingProviders(): void {
		$this->apps(['a' => new \stdClass(), 'b' => null]);
		$this->locator->expects($this->never())->method('contributionOf');

		$this->assertSame(['contributions' => []], $this->registry()->aggregateAnonymous());
	}//end testAnonymousSkipsNonContributingProviders()

	/**
	 * The registry under test.
	 *
	 * @param PortalManifestNormaliser|null $normaliser An optional normaliser.
	 *
	 * @return PortalContributionRegistry
	 */
	private function registry(?PortalManifestNormaliser $normaliser = null): PortalContributionRegistry {
		return new PortalContributionRegistry(
			appManager: $this->appManager,
			container: $this->createMock(ContainerInterface::class),
			logger: $this->logger,
			normaliser: ($normaliser ?? new PortalManifestNormaliser()),
			locator: $this->locator
		);
	}//end registry()

	/**
	 * Install the given apps, each with its provider or none.
	 *
	 * @param array<string,object|null> $providers Providers by app id.
	 *
	 * @return void
	 */
	private function apps(array $providers): void {
		$this->appManager->method('getInstalledApps')->willReturn(array_keys($providers));
		$this->locator->method('locate')->willReturnCallback(static fn (string $appId): ?object => $providers[$appId]);
	}//end apps()

	/**
	 * A provider serving the given audiences.
	 *
	 * @param array<int,string> $audiences The audiences.
	 *
	 * @return object
	 */
	private static function provider(array $audiences): object {
		return new class($audiences) {
			/**
			 * Build the provider.
			 *
			 * @param array<int,string> $audiences The audiences.
			 */
			public function __construct(private readonly array $audiences) {
			}

			/**
			 * @return array<int,string>
			 */
			public function getAudiences(): array {
				return $this->audiences;
			}

			/**
			 * @param array<string,mixed> $subject The subject.
			 *
			 * @return array<string,mixed>
			 */
			public function getContribution(array $subject): array {
				return [];
			}
		};
	}//end provider()
}//end class
