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
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T2
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

	/**
	 * A start tile's audiences are bounded by what the provider serves, in
	 * both aggregates, and the served list itself never leaves the registry.
	 *
	 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
	 */
	public function testAStartTileKeepsOnlyAudiencesTheProviderServes(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['citizen', 'business'];
			}

			public function getContribution(array $subject): array {
				return [
					'actions' => [
						[
							'id'        => 'createBezwaar',
							'endpoint'  => '/apps/portaliq/api/health',
							'method'    => 'POST',
							'anonymous' => true,
							'summary'   => 'Bent u het niet eens met een besluit? Maak binnen zes weken bezwaar.',
							'audiences' => ['citizen', 'alien'],
						],
					],
				];
			}
		};

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		$signedIn = $registry->aggregateFor(['audience' => 'citizen', 'organisation' => 'org-1', 'trust' => 'high'])['contributions'][0];
		$this->assertSame(['citizen'], $signedIn['actions'][0]['audiences']);
		$this->assertArrayNotHasKey('servedAudiences', $signedIn);

		$anonymous = $registry->aggregateAnonymous()['contributions'][0];
		$this->assertSame(['citizen'], $anonymous['actions'][0]['audiences']);
		$this->assertArrayNotHasKey('servedAudiences', $anonymous);
	}//end testAStartTileKeepsOnlyAudiencesTheProviderServes()

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
	 * site-member-voting-record-and-confidential-papers REQ-SCR-001: both
	 * aggregates carry the declared record lists with id, label, group and app,
	 * an anonymous one even when the contribution has no anonymous collection,
	 * and never a provider name. An entry naming a contract method is dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t1
	 */
	public function testBothAggregatesCarryPublicRecordsWithoutProviderNames(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['citizen'];
			}

			public function getContribution(array $subject): array {
				return [
					'label' => 'Raad',
					'collections' => [],
					'actions' => [],
					'publicRecords' => [
						['id' => 'memberVotingRecords', 'label' => 'Raadsleden', 'listProvider' => 'publicMembers', 'recordProvider' => 'publicVotingRecord'],
						['id' => 'bad', 'label' => 'Slecht', 'listProvider' => 'publicMembers', 'recordProvider' => 'getContribution'],
					],
				];
			}

			public function publicMembers(): array {
				return [];
			}

			public function publicVotingRecord(string $id): array {
				return [];
			}
		};

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		$anonymous = $registry->aggregateAnonymous();
		$subject   = $registry->aggregateFor(['audience' => 'citizen', 'organisation' => '']);
		foreach ([$anonymous['contributions'][0], $subject['contributions'][0]] as $contribution) {
			$this->assertSame([['id' => 'memberVotingRecords', 'label' => 'Raadsleden', 'group' => '', 'app' => 'portaliq']], $contribution['publicRecords']);
			$this->assertStringNotContainsString('publicMembers', json_encode($contribution));
		}

	}//end testBothAggregatesCarryPublicRecordsWithoutProviderNames()

	/**
	 * site-member-voting-record-and-confidential-papers REQ-SCR-004: a
	 * collection dropped for trust alone is named under `stepUp` with its label
	 * and the trust it needs, and nothing else. One the session already reaches,
	 * and one with a minTrust nobody can meet, are not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t6
	 */
	public function testACollectionDroppedForTrustIsNamedUnderStepUp(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['citizen'];
			}

			public function getContribution(array $subject): array {
				return [
					'label' => 'Raad',
					'collections' => [
						['id' => 'confidentialAgendaItems', 'label' => 'Vertrouwelijke stukken', 'register' => 'decidiq', 'schema' => 'agendaItem', 'minTrust' => 'substantial', 'scopeClaim' => 'x'],
						['id' => 'open', 'label' => 'Open', 'register' => 'decidiq', 'schema' => 'open', 'scopeClaim' => 'x'],
						['id' => 'typo', 'label' => 'Typo', 'register' => 'decidiq', 'schema' => 'typo', 'minTrust' => 'sustantial', 'scopeClaim' => 'x'],
					],
					'actions' => [],
				];
			}
		};
		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		$low  = $registry->aggregateFor(['audience' => 'citizen', 'trust' => 'low']);
		$high = $registry->aggregateFor(['audience' => 'citizen', 'trust' => 'substantial']);

		$this->assertSame([['app' => 'portaliq', 'collection' => 'confidentialAgendaItems', 'label' => 'Vertrouwelijke stukken', 'minTrust' => 'substantial']], $low['stepUp']);
		$this->assertSame([], $high['stepUp']);
		$this->assertSame(['open'], array_column($low['contributions'][0]['collections'], 'id'), 'the collection itself stays absent');

	}//end testACollectionDroppedForTrustIsNamedUnderStepUp()

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

	/**
	 * An app id whose namespace is not `ucfirst($appId)` must still be found.
	 *
	 * `zaakafhandelapp` declares `<namespace>ZaakAfhandelApp</namespace>`, so the
	 * old `ucfirst()` derivation asked for `OCA\Zaakafhandelapp\Portal\...` — a
	 * class the autoloader cannot produce. `class_exists()` answered false rather
	 * than raising, so the app was skipped in silence and its whole `citizen`
	 * contribution never reached the portal. Here the declared namespace resolves
	 * to a provider class that really exists, so a registry reading `<namespace>`
	 * returns one contribution and a registry guessing `ucfirst()` returns none.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T04
	 */
	public function testProviderIsFoundWhenTheNamespaceIsNotUcfirstOfTheAppId(): void {
		$provider = $this->createMock(PortalContributionProvider::class);
		$provider->method('getAudiences')->willReturn(['citizen']);
		$provider->method('getContribution')->willReturn(['label' => 'Zaken', 'collections' => [], 'actions' => []]);

		$registry = new PortalContributionRegistry(
			$this->appManager(['zaakafhandelapp'], ['zaakafhandelapp' => 'Portaliq']),
			$this->container($provider),
			$this->createMock(LoggerInterface::class)
		);

		$result = $registry->aggregateFor(['audience' => 'citizen', 'organisation' => 'org-1']);

		$this->assertCount(1, $result['contributions']);
		$this->assertSame('zaakafhandelapp', $result['contributions'][0]['app']);
		$this->assertSame('Zaken', $result['contributions'][0]['label']);

	}//end testProviderIsFoundWhenTheNamespaceIsNotUcfirstOfTheAppId()

	/**
	 * An app declaring no `<namespace>` still resolves through `ucfirst()`.
	 *
	 * The fallback is what keeps every already-working app working, so it needs
	 * its own control: without it a passing test above would not distinguish
	 * "reads the declared namespace" from "stopped resolving anything else".
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T04
	 */
	public function testProviderStillResolvesWhenTheAppDeclaresNoNamespace(): void {
		$provider = $this->createMock(PortalContributionProvider::class);
		$provider->method('getAudiences')->willReturn(['supplier']);
		$provider->method('getContribution')->willReturn(['label' => 'Voorbeeld', 'collections' => [], 'actions' => []]);

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->container($provider),
			$this->createMock(LoggerInterface::class)
		);

		$result = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'org-1']);

		$this->assertCount(1, $result['contributions']);
		$this->assertSame('portaliq', $result['contributions'][0]['app']);

	}//end testProviderStillResolvesWhenTheAppDeclaresNoNamespace()

	/**
	 * The aggregate resolves attached actions (woo-journey-entry-points D3):
	 * an action with `attachTo` lands on the collection it names.
	 *
	 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
	 */
	public function testTheAggregateResolvesAttachedActions(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['client'];
			}

			public function getContribution(array $subject): array {
				return [
					'collections' => [['id' => 'dossiers', 'register' => 'portaliq', 'schema' => 'collection', 'scopeField' => 'owner']],
					'actions' => [[
						'id' => 'ask',
						'label' => 'Stel een vraag',
						'endpoint' => '/apps/portaliq/api/health',
						'method' => 'POST',
						'rowField' => 'collectionId',
						'attachTo' => ['app' => 'portaliq', 'schema' => 'collection'],
					]],
				];
			}
		};

		$registry = new PortalContributionRegistry(
			$this->appManager(['portaliq']),
			$this->anyContainer($provider),
			$this->createMock(LoggerInterface::class)
		);

		$result = $registry->aggregateFor(['audience' => 'client', 'organisation' => 'org-1', 'trust' => 'low']);
		$this->assertSame(
			[['app' => 'portaliq', 'id' => 'ask', 'label' => 'Stel een vraag']],
			$result['contributions'][0]['collections'][0]['attachedActions'] ?? null
		);
	}//end testTheAggregateResolvesAttachedActions()

	/**
	 * A malformed row condition is dropped from the aggregate, and logged with
	 * the app (update-row-action-condition REQ-URC-002).
	 *
	 * @spec openspec/changes/update-row-action-condition/specs/portal-contribution-contract/spec.md#requirement-a-malformed-row-condition-must-be-dropped-with-a-warning-req-urc-002
	 */
	public function testAMalformedRowConditionIsDroppedFromTheAggregate(): void {
		$provider = new class {

			public function getAudiences(): array {
				return ['parent'];
			}

			public function getContribution(array $subject): array {
				return [
					'collections' => [['id' => 'times', 'register' => 'portaliq', 'schema' => 'collection', 'scopeField' => 'owner', 'rowActions' => ['cancel', 'withdraw']]],
					'actions' => [
						[
							'id' => 'cancel',
							'type' => 'update',
							'schema' => 'collection',
							'fields' => ['lifecycle'],
							'set' => ['lifecycle' => 'cancelled'],
							'rowWhen' => ['field' => 'lifecycle', 'in' => []],
						],
						[
							'id' => 'withdraw',
							'type' => 'update',
							'schema' => 'collection',
							'fields' => ['lifecycle'],
							'set' => ['lifecycle' => 'withdrawn'],
							'rowWhen' => ['field' => 'lifecycle', 'in' => ['booked']],
						],
					],
				];
			}
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Portaliq: row condition dropped', $this->callback(static fn (array $context): bool => $context['app'] === 'portaliq'));

		$registry = new PortalContributionRegistry($this->appManager(['portaliq']), $this->anyContainer($provider), $logger);

		$actions = $registry->aggregateFor(['audience' => 'parent', 'organisation' => 'org-1', 'trust' => 'low'])['contributions'][0]['actions'];
		$this->assertArrayNotHasKey('rowWhen', $actions[0]);
		$this->assertSame(['field' => 'lifecycle', 'in' => ['booked']], $actions[1]['rowWhen']);
	}//end testAMalformedRowConditionIsDroppedFromTheAggregate()

	/**
	 * @param array<int, string>    $installed  App ids `getInstalledApps()` reports.
	 * @param array<string, string> $namespaces App id => `info.xml` `<namespace>`.
	 */
	private function appManager(array $installed, array $namespaces = []): IAppManager {
		$mock = $this->createMock(IAppManager::class);
		$mock->method('getInstalledApps')->willReturn($installed);
		$mock->method('getAppInfo')->willReturnCallback(
			static function (string $appId) use ($namespaces): ?array {
				if (array_key_exists($appId, $namespaces) === false) {
					// Mirrors an app that declares no <namespace> at all.
					return ['id' => $appId];
				}

				return ['id' => $appId, 'namespace' => $namespaces[$appId]];
			}
		);
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
