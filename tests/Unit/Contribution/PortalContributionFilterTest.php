<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalContributionFilter;
use PHPUnit\Framework\TestCase;

/**
 * Portal scoping for contributed surfaces.
 *
 * BOTH DIRECTIONS IN EVERY SCENARIO THAT HAS TWO. A filter that returned the
 * empty list unconditionally would satisfy every "must not appear" assertion
 * on its own, and that is the failure this class is most likely to have: it is
 * a filter, and the broken-filter state is "keeps nothing". So each exclusion
 * test is paired with an inclusion on the same input.
 */
class PortalContributionFilterTest extends TestCase {

	private PortalContributionFilter $filter;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filter = new PortalContributionFilter();
	}//end setUp()


	/**
	 * The ADR-046 promise: a provider written before portal targeting existed
	 * keeps working, unedited, on every portal.
	 *
	 * @return void
	 */
	public function testAContributionWithNoTargetAppearsOnEveryPortal(): void {
		$contributions = [['app' => 'procest', 'label' => 'Zaken']];

		$tilburg = $this->filter->forPortal(contributions: $contributions, portalSlug: 'open-tilburg');
		$venray = $this->filter->forPortal(contributions: $contributions, portalSlug: 'open-venray');

		$this->assertCount(1, $tilburg, 'an untargeted contribution belongs on this portal');
		$this->assertCount(1, $venray, 'and on that one — the ADR-046 contract is unchanged');
	}//end testAContributionWithNoTargetAppearsOnEveryPortal()


	/**
	 * The capability the field exists for, asserted in BOTH directions from
	 * one input so a keep-nothing filter cannot pass.
	 *
	 * @return void
	 */
	public function testADeclaredTargetIncludesTheNamedPortalAndExcludesOthers(): void {
		$contributions = [
			['app' => 'shillinq', 'label' => 'Facturen', 'portals' => ['open-venray']],
		];

		$venray = $this->filter->forPortal(contributions: $contributions, portalSlug: 'open-venray');
		$tilburg = $this->filter->forPortal(contributions: $contributions, portalSlug: 'open-tilburg');

		$this->assertCount(1, $venray, 'the named portal must still receive it');
		$this->assertSame('shillinq', $venray[0]['app']);
		$this->assertSame([], $tilburg, 'an unnamed portal must not');
	}//end testADeclaredTargetIncludesTheNamedPortalAndExcludesOthers()


	/**
	 * One target among several is a list membership test, not a first-entry
	 * test — the shape a `$targets[0] === $slug` implementation would fail.
	 *
	 * @return void
	 */
	public function testAMultiPortalTargetMatchesAnyOfItsEntries(): void {
		$contributions = [
			['app' => 'pipelinq', 'portals' => ['open-venray', 'open-tilburg', 'other']],
		];

		$this->assertCount(
			1,
			$this->filter->forPortal(contributions: $contributions, portalSlug: 'open-tilburg'),
			'a middle entry counts exactly as much as the first'
		);
	}//end testAMultiPortalTargetMatchesAnyOfItsEntries()


	/**
	 * An EMPTY list is a deliberate "nowhere" and must not collapse into the
	 * absent-key case, which means "everywhere". Those two are one typo apart
	 * and mean opposite things.
	 *
	 * @return void
	 */
	public function testAnEmptyTargetListPublishesNowhere(): void {
		$contributions = [['app' => 'docudesk', 'portals' => []]];

		$this->assertSame(
			[],
			$this->filter->forPortal(contributions: $contributions, portalSlug: 'open-tilburg'),
			'an empty list parks the contribution rather than publishing it'
		);
	}//end testAnEmptyTargetListPublishesNowhere()


	/**
	 * A malformed target fails CLOSED (ADR-005). Reading a broken `portals`
	 * as "no target" would publish everywhere a provider meant to narrow —
	 * the exact outcome the field exists to prevent, reached by a typo.
	 *
	 * @return void
	 */
	public function testAMalformedTargetFailsClosed(): void {
		foreach (['open-venray', 42, null, true] as $malformed) {
			$this->assertSame(
				[],
				$this->filter->forPortal(
					contributions: [['app' => 'x', 'portals' => $malformed]],
					portalSlug: 'open-tilburg'
				),
				'a non-list target must not be read as "no target"'
			);
		}
	}//end testAMalformedTargetFailsClosed()


	/**
	 * The response for one portal must not name another portal's slug. The
	 * target list is how an author narrows; it is not something a consumer
	 * needs, and shipping it turns every contribution into a directory of the
	 * installation's other tenants.
	 *
	 * @return void
	 */
	public function testTheTargetListIsNotEchoedToTheConsumer(): void {
		$kept = $this->filter->forPortal(
			contributions: [['app' => 'shillinq', 'portals' => ['open-tilburg', 'a-competitor']]],
			portalSlug: 'open-tilburg'
		);

		$this->assertCount(1, $kept);
		$this->assertArrayNotHasKey('portals', $kept[0], 'the other portal must not be disclosed');
	}//end testTheTargetListIsNotEchoedToTheConsumer()


	/**
	 * A mixed aggregate keeps its targeted and untargeted members apart, and
	 * preserves the ones that belong — the realistic case, where a
	 * keep-nothing bug would otherwise hide behind a single-entry fixture.
	 *
	 * @return void
	 */
	public function testAMixedAggregateIsPartitionedNotEmptied(): void {
		$contributions = [
			['app' => 'procest', 'label' => 'Zaken'],
			['app' => 'shillinq', 'label' => 'Facturen', 'portals' => ['open-venray']],
			['app' => 'pipelinq', 'label' => 'Offertes', 'portals' => ['open-tilburg']],
		];

		$kept = $this->filter->forPortal(contributions: $contributions, portalSlug: 'open-tilburg');

		$this->assertSame(
			['procest', 'pipelinq'],
			array_column($kept, 'app'),
			'the untargeted one and the one naming this portal, in order, and nothing else'
		);
	}//end testAMixedAggregateIsPartitionedNotEmptied()


	/**
	 * A non-array member is skipped rather than crashing the whole portal's
	 * content API — a provider returning junk takes out its own surface only.
	 *
	 * @return void
	 */
	public function testANonArrayMemberIsSkipped(): void {
		$kept = $this->filter->forPortal(
			contributions: ['not an array', ['app' => 'procest']],
			portalSlug: 'open-tilburg'
		);

		$this->assertCount(1, $kept);
		$this->assertSame('procest', $kept[0]['app']);
	}//end testANonArrayMemberIsSkipped()


}//end class
