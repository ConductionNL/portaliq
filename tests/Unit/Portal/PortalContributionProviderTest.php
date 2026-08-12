<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Portal;

use OCA\Portaliq\Portal\PortalContributionProvider;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the built-in, config-driven contribution provider
 * (portal-page-provisioning): reading active `portalPage` objects through
 * PortalObjectReader, converting them 1:1 into the manifest shape, the
 * distinct-audience discovery, the first-match-not-merge rule on a
 * same-audience collision, and the contribution-level `minTrust`
 * default-with-override semantics.
 *
 * @spec openspec/changes/portal-page-provisioning/tasks.md#3.1
 * @spec openspec/changes/portal-page-provisioning/tasks.md#3.2
 * @spec openspec/changes/portal-page-provisioning/tasks.md#3.3
 */
class PortalContributionProviderTest extends TestCase {

	public function testGetAudiencesReturnsDistinctActiveAudiences(): void {
		$provider = $this->provider(
			[
				['id' => 'p1', 'audience' => 'citizen', 'status' => 'active'],
				['id' => 'p2', 'audience' => 'supplier', 'status' => 'active'],
				['id' => 'p3', 'audience' => 'citizen', 'status' => 'active'],
			]
		);

		$this->assertSame(['citizen', 'supplier'], $provider->getAudiences());
		$this->assertSame('citizen', $provider->getAudience());

	}//end testGetAudiencesReturnsDistinctActiveAudiences()

	public function testGetAudiencesEmptyWhenNoActivePortalPages(): void {
		$provider = $this->provider([]);

		$this->assertSame([], $provider->getAudiences());
		$this->assertSame('', $provider->getAudience());

	}//end testGetAudiencesEmptyWhenNoActivePortalPages()

	public function testGetContributionConvertsRowToManifestShape(): void {
		$provider = $this->provider(
			[
				[
					'id' => 'p1',
					'label' => 'Meldingen',
					'audience' => 'citizen',
					'status' => 'active',
					'collections' => [['id' => 'c1', 'register' => 'openbuild', 'schema' => 'melding']],
					'actions' => [['id' => 'a1', 'type' => 'create', 'register' => 'openbuild', 'schema' => 'melding']],
					'pages' => [['id' => 'pg1', 'label' => 'Melden', 'blocks' => [['type' => 'action', 'action' => 'a1']]]],
				],
			]
		);

		$contribution = $provider->getContribution(['audience' => 'citizen']);

		$this->assertNotNull($contribution);
		$this->assertSame('Meldingen', $contribution['label']);
		$this->assertSame([['id' => 'c1', 'register' => 'openbuild', 'schema' => 'melding']], $contribution['collections']);
		$this->assertSame([['id' => 'a1', 'type' => 'create', 'register' => 'openbuild', 'schema' => 'melding']], $contribution['actions']);
		$this->assertCount(1, $contribution['pages']);

	}//end testGetContributionConvertsRowToManifestShape()

	public function testGetContributionReturnsNullWhenNoMatchForAudience(): void {
		$provider = $this->provider(
			[
				['id' => 'p1', 'audience' => 'citizen', 'status' => 'active', 'label' => 'X', 'collections' => [], 'actions' => []],
			]
		);

		$this->assertNull($provider->getContribution(['audience' => 'supplier']));
		$this->assertNull($provider->getContribution([]));

	}//end testGetContributionReturnsNullWhenNoMatchForAudience()

	/**
	 * Multiple active `portalPage` objects for the SAME audience: the
	 * provider picks the FIRST (by object id, ascending) and does NOT merge
	 * — merging two independently-authored manifests could silently combine
	 * an unrelated app's field whitelist with another's (design.md, OQ2). A
	 * warning is logged on the collision.
	 */
	public function testMultipleActiveForSameAudiencePicksFirstAndLogsWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('multiple active portalPage'),
			$this->callback(static fn (array $ctx) => ($ctx['audience'] ?? null) === 'citizen' && ($ctx['count'] ?? null) === 2)
		);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn(
			[
				['id' => 'z-second', 'audience' => 'citizen', 'status' => 'active', 'label' => 'Second', 'collections' => [], 'actions' => []],
				['id' => 'a-first', 'audience' => 'citizen', 'status' => 'active', 'label' => 'First', 'collections' => [], 'actions' => []],
			]
		);

		$provider = new PortalContributionProvider($reader, $logger);
		$contribution = $provider->getContribution(['audience' => 'citizen']);

		$this->assertSame('First', $contribution['label']);

	}//end testMultipleActiveForSameAudiencePicksFirstAndLogsWarning()

	/**
	 * An entry that does not declare its OWN `minTrust` inherits the
	 * contribution-level default; an entry that DOES declare one is never
	 * touched — the entry's own value always wins.
	 */
	public function testContributionLevelMinTrustFillsEntriesLackingOwnMinTrust(): void {
		$provider = $this->provider(
			[
				[
					'id' => 'p1',
					'audience' => 'supplier',
					'status' => 'active',
					'label' => 'X',
					'minTrust' => 'substantial',
					'collections' => [
						['id' => 'inherits', 'register' => 'r', 'schema' => 'a'],
						['id' => 'overrides', 'register' => 'r', 'schema' => 'b', 'minTrust' => 'low'],
					],
					'actions' => [
						['id' => 'inheritsAction', 'type' => 'create', 'register' => 'r', 'schema' => 'a'],
					],
				],
			]
		);

		$contribution = $provider->getContribution(['audience' => 'supplier']);
		$collections = array_column($contribution['collections'], null, 'id');
		$actions = array_column($contribution['actions'], null, 'id');

		$this->assertSame('substantial', $collections['inherits']['minTrust']);
		$this->assertSame('low', $collections['overrides']['minTrust']);
		$this->assertSame('substantial', $actions['inheritsAction']['minTrust']);

	}//end testContributionLevelMinTrustFillsEntriesLackingOwnMinTrust()

	/**
	 * `activePortalPages()` — the shared read every public method funnels
	 * through — narrows to `status: active` via the reader's own `filter`
	 * parameter, and deliberately queries with an EMPTY `scopeField`/
	 * `subjectRef`: `portalPage` rows are configuration, not subject data,
	 * so there is no per-subject scope to enforce here (a `draft` row is
	 * excluded by the filter before this class ever sees it).
	 */
	public function testActivePortalPagesQueriesOnlyActiveStatus(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->once())->method('readCollection')->with(
			'portaliq',
			'portalPage',
			'',
			'',
			'',
			200,
			'',
			'',
			null,
			'',
			null,
			['status' => 'active']
		)->willReturn([]);

		$provider = new PortalContributionProvider($reader, $this->createMock(LoggerInterface::class));
		$provider->getAudiences();

	}//end testActivePortalPagesQueriesOnlyActiveStatus()

	/**
	 * @param array<int, array<string, mixed>> $rows The rows the mocked
	 *                                               reader returns.
	 */
	private function provider(array $rows): PortalContributionProvider {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn($rows);

		return new PortalContributionProvider($reader, $this->createMock(LoggerInterface::class));
	}//end provider()

}//end class
