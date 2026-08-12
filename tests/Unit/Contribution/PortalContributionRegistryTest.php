<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Portal\PortalContributionProvider;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the registry's convention-FQCN discovery, audience filtering, and
 * tolerance of apps without a provider. Contract v2 adds the multi-audience
 * (`getAudiences()`) discovery matrix and the fail-closed `minTrust` manifest
 * filtering inside aggregateFor(). portal-page-provisioning adds
 * `aggregateAnonymous()` — the no-subject sibling that surfaces only
 * `anonymous: true` entries fleet-wide. The built-in
 * `OCA\Portaliq\Portal\PortalContributionProvider` (now config-driven, reading
 * `portalPage` OpenRegister objects) is exercised in its OWN dedicated test
 * (tests/Unit/Portal/PortalContributionProviderTest.php); here it is always a
 * mock so registry-algorithm tests do not depend on OpenRegister at all.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 * @spec openspec/changes/contract-v2/tasks.md#T2
 * @spec openspec/changes/portal-page-provisioning/tasks.md#2.1
 */
class PortalContributionRegistryTest extends TestCase {

	private const PROVIDER_FQCN = 'OCA\\Portaliq\\Portal\\PortalContributionProvider';

	public function testAggregatesMatchingAudienceAndSkipsAppsWithoutProvider(): void {
		// 'someotherapp' has no OCA\Someotherapp\Portal\PortalContributionProvider
		// class, so class_exists() skips it before the container is even asked.
		$provider = $this->createMock(PortalContributionProvider::class);
		$provider->method('getAudiences')->willReturn(['supplier']);
		$provider->method('getContribution')->willReturn(['label' => 'Voorbeeld', 'collections' => [], 'actions' => []]);

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq', 'someotherapp']),
			$this->container($provider),
			$this->createMock(LoggerInterface::class)
		);

		$result = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'org-1']);

		$this->assertSame('supplier', $result['audience']);
		$this->assertCount(1, $result['contributions']);
		$this->assertSame('portaliq', $result['contributions'][0]['app']);
		$this->assertSame('org-1', $result['organisation']);

	}//end testAggregatesMatchingAudienceAndSkipsAppsWithoutProvider()

	public function testNonMatchingAudienceYieldsNothing(): void {
		$provider = $this->createMock(PortalContributionProvider::class);
		$provider->method('getAudiences')->willReturn(['supplier']);
		$provider->method('getContribution')->willReturn(['label' => 'Voorbeeld', 'collections' => [], 'actions' => []]);

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->container($provider),
			$this->createMock(LoggerInterface::class)
		);

		$result = $registry->aggregateFor(['audience' => 'client', 'organisation' => 'org-1']);
		$this->assertCount(0, $result['contributions']);

	}//end testNonMatchingAudienceYieldsNothing()

	public function testMultiAudienceProviderIsConsultedForEachListedAudience(): void {
		// Duck-typed getAudiences() is preferred; getAudience() would say
		// 'supplier' only, so consulting for 'client' proves the preference.
		$provider = new class {

			public function getAudiences(): array {
				return ['client', 'supplier'];
			}

			public function getAudience(): string {
				return 'supplier';
			}

			public function getContribution(array $subject): array {
				return ['label' => 'Multi', 'collections' => [], 'actions' => []];
			}
		};

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		foreach (['supplier', 'client'] as $audience) {
			$result = $registry->aggregateFor(['audience' => $audience, 'organisation' => 'org-1']);
			$this->assertCount(1, $result['contributions'], "audience '{$audience}' must be served");
		}

		// An audience outside the list is NOT served.
		$result = $registry->aggregateFor(['audience' => 'citizen', 'organisation' => 'org-1']);
		$this->assertCount(0, $result['contributions']);

	}//end testMultiAudienceProviderIsConsultedForEachListedAudience()

	public function testProviderExercisingTheV2VocabularyIsFilteredByTrust(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['supplier'];
			}

			public function getContribution(array $subject): array {
				return [
					'label' => 'Voorbeeld',
					'collections' => [
						['id' => 'claimScoped', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeClaim' => 'exampleContactId'],
					],
					'actions' => [
						['id' => 'forward', 'endpoint' => '/apps/portaliq/api/health', 'method' => 'GET'],
						['id' => 'trusted', 'endpoint' => '/apps/portaliq/api/health', 'method' => 'GET', 'minTrust' => 'substantial'],
					],
				];
			}
		};

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		// A dev-login (low-trust) supplier: the claim-scoped collection and the
		// placeholder endpoint action are visible, the substantial-gated action
		// is filtered out of the manifest.
		$low = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'dev-org', 'trust' => 'low']);
		$collections = array_column($low['contributions'][0]['collections'], null, 'id');
		$this->assertArrayHasKey('claimScoped', $collections);
		$this->assertSame('exampleContactId', $collections['claimScoped']['scopeClaim']);

		$actions = array_column($low['contributions'][0]['actions'], null, 'id');
		$this->assertArrayHasKey('forward', $actions);
		$this->assertSame('/apps/portaliq/api/health', $actions['forward']['endpoint']);
		$this->assertArrayNotHasKey('trusted', $actions);

		// A substantial-trust supplier sees the gated action too.
		$substantial = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'dev-org', 'trust' => 'substantial']);
		$actions = array_column($substantial['contributions'][0]['actions'], null, 'id');
		$this->assertArrayHasKey('trusted', $actions);

	}//end testProviderExercisingTheV2VocabularyIsFilteredByTrust()

	public function testMinTrustFiltersCollectionsAndActionsFailClosed(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['supplier'];
			}

			public function getContribution(array $subject): array {
				return [
					'label' => 'Trusted',
					'collections' => [
						['id' => 'open', 'register' => 'r', 'schema' => 'a'],
						['id' => 'gated', 'register' => 'r', 'schema' => 'b', 'minTrust' => 'substantial'],
						['id' => 'typo', 'register' => 'r', 'schema' => 'c', 'minTrust' => 'ultra'],
					],
					'actions' => [
						['id' => 'lowAction', 'type' => 'create', 'register' => 'r', 'schema' => 'a'],
						['id' => 'highAction', 'endpoint' => '/apps/x/api/y', 'minTrust' => 'high'],
					],
				];
			}
		};

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		// A low-trust subject (legacy 'dev' normalises to low) sees only the
		// unguarded entries.
		$low = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'org-1', 'trust' => 'dev']);
		$this->assertSame(['open'], array_column($low['contributions'][0]['collections'], 'id'));
		$this->assertSame(['lowAction'], array_column($low['contributions'][0]['actions'], 'id'));

		// A high-trust subject sees everything EXCEPT the unrecognised-minTrust
		// entry — a typo must never widen access (unsatisfiable for everyone).
		$high = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'org-1', 'trust' => 'high']);
		$this->assertSame(['open', 'gated'], array_column($high['contributions'][0]['collections'], 'id'));
		$this->assertSame(['lowAction', 'highAction'], array_column($high['contributions'][0]['actions'], 'id'));

	}//end testMinTrustFiltersCollectionsAndActionsFailClosed()

	/**
	 * portal-page-provisioning (task 6.2): `aggregateAnonymous()` keeps only
	 * `anonymous: true` entries and drops every private sibling in the SAME
	 * contribution — a contribution mixing a private collection with one
	 * public intake action must never leak the private one to an anonymous
	 * caller.
	 */
	public function testAggregateAnonymousSurfacesOnlyAnonymousEntriesAndDropsPrivateSiblings(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['citizen'];
			}

			public function getContribution(array $subject): array {
				return [
					'label' => 'Meldingen',
					'collections' => [
						['id' => 'private', 'register' => 'r', 'schema' => 'a'],
						['id' => 'public', 'register' => 'r', 'schema' => 'b', 'anonymous' => true],
					],
					'actions' => [
						['id' => 'privateAction', 'type' => 'update', 'register' => 'r', 'schema' => 'a'],
						['id' => 'publicIntake', 'type' => 'create', 'register' => 'r', 'schema' => 'c', 'anonymous' => true],
					],
				];
			}
		};

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		$result = $registry->aggregateAnonymous();

		$this->assertCount(1, $result['contributions']);
		$this->assertSame(['public'], array_column($result['contributions'][0]['collections'], 'id'));
		$this->assertSame(['publicIntake'], array_column($result['contributions'][0]['actions'], 'id'));

	}//end testAggregateAnonymousSurfacesOnlyAnonymousEntriesAndDropsPrivateSiblings()

	/**
	 * A provider/audience contributing zero anonymous entries is omitted
	 * entirely — an anonymous caller never sees an empty contribution shell.
	 */
	public function testAggregateAnonymousOmitsContributionsWithNoAnonymousEntries(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['supplier'];
			}

			public function getContribution(array $subject): array {
				return [
					'label' => 'Voorbeeld',
					'collections' => [['id' => 'private', 'register' => 'r', 'schema' => 'a']],
					'actions' => [['id' => 'privateAction', 'type' => 'update', 'register' => 'r', 'schema' => 'a']],
				];
			}
		};

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		$result = $registry->aggregateAnonymous();
		$this->assertSame([], $result['contributions']);

	}//end testAggregateAnonymousOmitsContributionsWithNoAnonymousEntries()

	/**
	 * The fail-closed anonymous/minTrust mutual exclusion (normaliser) can
	 * itself drop `anonymous` from an entry that also declares a non-low
	 * `minTrust`. `aggregateAnonymous()` filters a SECOND time after
	 * normalisation, so a flag-stripped entry can never survive into an
	 * aggregate an anonymous caller consumes — a malformed manifest entry
	 * cannot widen access.
	 */
	public function testAggregateAnonymousDropsEntryStrippedByMutualExclusion(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['citizen'];
			}

			public function getContribution(array $subject): array {
				return [
					'label' => 'Gated',
					'collections' => [],
					'actions' => [
						['id' => 'contradictory', 'type' => 'create', 'register' => 'r', 'schema' => 'a', 'anonymous' => true, 'minTrust' => 'substantial'],
					],
				];
			}
		};

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		$result = $registry->aggregateAnonymous();
		$this->assertSame([], $result['contributions']);

	}//end testAggregateAnonymousDropsEntryStrippedByMutualExclusion()

	/**
	 * A multi-audience provider is consulted for EVERY audience it serves —
	 * there is no single subject audience to filter by on the anonymous
	 * path, so each audience's anonymous entries surface as its own
	 * contribution.
	 */
	public function testAggregateAnonymousConsultsEveryAudienceAProviderServes(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['supplier', 'citizen'];
			}

			public function getContribution(array $subject): array {
				$audience = ($subject['audience'] ?? '');
				return [
					'label' => 'For ' . $audience,
					'collections' => [],
					'actions' => [
						['id' => 'intake-' . $audience, 'type' => 'create', 'register' => 'r', 'schema' => $audience, 'anonymous' => true],
					],
				];
			}
		};

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		$result = $registry->aggregateAnonymous();
		$this->assertCount(2, $result['contributions']);

		$actionIds = [];
		foreach ($result['contributions'] as $contribution) {
			$actionIds = array_merge($actionIds, array_column($contribution['actions'], 'id'));
		}

		$this->assertContains('intake-supplier', $actionIds);
		$this->assertContains('intake-citizen', $actionIds);

	}//end testAggregateAnonymousConsultsEveryAudienceAProviderServes()

	private function appManager(array $installed): IAppManager {
		$mock = $this->createMock(IAppManager::class);
		$mock->method('getInstalledApps')->willReturn($installed);
		return $mock;
	}//end appManager()

	private function container(PortalContributionProvider $provider): ContainerInterface {
		return $this->anyContainer($provider);
	}//end container()

	private function anyContainer(object $provider): ContainerInterface {
		$mock = $this->createMock(ContainerInterface::class);
		$mock->method('get')->willReturnCallback(
			function (string $id) use ($provider) {
				if ($id === self::PROVIDER_FQCN) {
					return $provider;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);
		return $mock;
	}//end anyContainer()

}//end class
