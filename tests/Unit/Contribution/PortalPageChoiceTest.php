<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalPageChoice;
use PHPUnit\Framework\TestCase;

/**
 * operate-pages-per-portal-and-client: the page choice of a portal (its menu)
 * and of one client account (what that account may see).
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md
 */
class PortalPageChoiceTest extends TestCase {

	/**
	 * Two apps: pipelinq with quotes and invoices, shillinq with payments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function contributions(): array {
		return [
			[
				'app' => 'pipelinq',
				'collections' => [
					['id' => 'quotes'],
					['id' => 'invoices'],
					['id' => 'contacts'],
					['id' => 'messages', 'kind' => 'inbox'],
				],
				'pages' => [
					['id' => 'quotes', 'blocks' => [['type' => 'collection', 'collection' => 'quotes'], ['type' => 'detail', 'collection' => 'quotes']]],
					['id' => 'invoices', 'blocks' => [['type' => 'collection', 'collection' => 'invoices'], ['type' => 'collection', 'collection' => 'contacts']]],
					['id' => 'contacts', 'blocks' => [['type' => 'collection', 'collection' => 'contacts']]],
				],
			],
			[
				'app' => 'shillinq',
				'collections' => [['id' => 'payments']],
				'pages' => [
					['id' => 'payments', 'blocks' => [['type' => 'collection', 'collection' => 'payments']]],
				],
			],
		];
	}//end contributions()

	/**
	 * The page ids of an aggregate, flattened in order as the menu shows them.
	 *
	 * @param array<int, array<string, mixed>> $contributions The contributions.
	 *
	 * @return array<int, string>
	 */
	private function menu(array $contributions): array {
		$ids = [];
		foreach ($contributions as $contribution) {
			foreach ($contribution['pages'] as $page) {
				$ids[] = $contribution['app'].':'.$page['id'];
			}
		}

		return $ids;
	}//end menu()

	public function testHiddenPageForAccountDropsItsCollections(): void {
		$choice = new PortalPageChoice();
		$out = $choice->hideForAccount(contributions: $this->contributions(), hiddenPages: ['pipelinq:invoices']);

		$this->assertSame(['pipelinq:quotes', 'pipelinq:contacts', 'shillinq:payments'], $this->menu($out));
		$ids = array_column($out[0]['collections'], 'id');
		// invoices was shown only by the hidden page: closed. contacts is still
		// on another page; the inbox collection was on no page at all: both stay.
		$this->assertSame(['quotes', 'contacts', 'messages'], $ids);
	}//end testHiddenPageForAccountDropsItsCollections()

	public function testNoHiddenPagesLeavesTheAggregateAsItIs(): void {
		$choice = new PortalPageChoice();
		$this->assertSame($this->contributions(), $choice->hideForAccount(contributions: $this->contributions(), hiddenPages: []));
	}//end testNoHiddenPagesLeavesTheAggregateAsItIs()

	public function testPortalNavigationHidesAndOrdersPages(): void {
		$choice = new PortalPageChoice();
		$out = $choice->applyNavigation(
			contributions: $this->contributions(),
			navigation: [
				['page' => 'shillinq:payments', 'hidden' => false],
				['page' => 'pipelinq:contacts', 'hidden' => false],
				['page' => 'pipelinq:quotes', 'hidden' => true],
				['page' => 'pipelinq:invoices', 'hidden' => false],
			]
		);

		$this->assertSame(['shillinq:payments', 'pipelinq:contacts', 'pipelinq:invoices'], $this->menu($out));
		// Presentation only: the hidden page's collection is still served.
		$this->assertContains('quotes', array_column($out[1]['collections'], 'id'));
	}//end testPortalNavigationHidesAndOrdersPages()

	public function testUnlistedPageKeepsItsPlace(): void {
		$choice = new PortalPageChoice();
		$out = $choice->applyNavigation(
			contributions: $this->contributions(),
			navigation: [['page' => 'pipelinq:contacts', 'hidden' => false]]
		);

		// The listed page first; the pages nobody chose about follow, in the
		// order the apps gave them, so an app installed later still shows.
		$this->assertSame(['pipelinq:contacts', 'pipelinq:quotes', 'pipelinq:invoices', 'shillinq:payments'], $this->menu($out));
	}//end testUnlistedPageKeepsItsPlace()

	public function testNoChoiceAnswersAsToday(): void {
		$choice = new PortalPageChoice();
		$this->assertSame($this->contributions(), $choice->applyNavigation(contributions: $this->contributions(), navigation: []));
	}//end testNoChoiceAnswersAsToday()

	public function testTheListForTheSubjectsAudienceIsTheOneApplied(): void {
		$choice = new PortalPageChoice();
		$portal = [
			'navigation' => [
				'supplier' => [['page' => 'pipelinq:quotes', 'hidden' => true]],
				'client'   => [['page' => 'pipelinq:invoices', 'hidden' => true]],
			],
		];

		$this->assertSame([['page' => 'pipelinq:invoices', 'hidden' => true]], $choice->navigationFor(portal: $portal, audience: 'client'));
		$this->assertSame([], $choice->navigationFor(portal: $portal, audience: 'business'));
		$this->assertSame([], $choice->navigationFor(portal: null, audience: 'client'));
		$this->assertSame([], $choice->navigationFor(portal: ['navigation' => ['client' => 'nope']], audience: 'client'));
	}//end testTheListForTheSubjectsAudienceIsTheOneApplied()
}//end class
