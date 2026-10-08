<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalEntryPointContent;
use PHPUnit\Framework\TestCase;

/**
 * portal-intake-form-as-an-object T09: the entry point as content an editor
 * arranges, seeded once so there is something to rearrange.
 *
 * 🔴 IT SEEDS ONCE AND NEVER OVERWRITES. An editor who moves a topic, renames
 * a page or deletes one has made a decision, and a provisioner that re-asserts
 * its own layout on the next run undoes that decision silently, at whatever
 * hour the job happens to run.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalEntryPointContentTest extends TestCase {

	private PortalEntryPointContent $content;

	/**
	 * Wire the content.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->content = new PortalEntryPointContent();
	}//end setUp()

	/**
	 * A fresh portal is given the entry page and its topics.
	 *
	 * @return void
	 */
	public function testAFreshPortalIsSeeded(): void {
		$plan = $this->content->plan(portal: 'gemeente', existingRoutes: []);

		$this->assertSame(1, $plan['createdCount']);
		$this->assertSame(0, $plan['skippedCount']);
		$this->assertNotSame([], $plan['topics']);
	}//end testAFreshPortalIsSeeded()

	/**
	 * 🔴 AN EXISTING ROUTE IS LEFT ALONE, AND THE PLAN SAYS WHY. Overwriting it
	 * undoes an editor's decision with nothing on any screen to say it happened.
	 *
	 * @return void
	 */
	public function testAnExistingRouteIsLeftAloneWithAReason(): void {
		$plan = $this->content->plan(portal: 'gemeente', existingRoutes: [PortalEntryPointContent::ENTRY_ROUTE]);

		$this->assertSame(0, $plan['createdCount']);
		$this->assertSame(1, $plan['skippedCount']);
		$this->assertStringContainsString('left exactly as it is', $plan['skip'][0]['reason']);
	}//end testAnExistingRouteIsLeftAloneWithAReason()

	/**
	 * 🔴 AND NO TOPICS ARE SEEDED BESIDE A PAGE THAT WAS LEFT ALONE. Dropping
	 * containers onto somebody else's arrangement is the same overwrite in a
	 * smaller form.
	 *
	 * @return void
	 */
	public function testNoTopicsAreSeededOntoAnExistingArrangement(): void {
		$plan = $this->content->plan(portal: 'gemeente', existingRoutes: [PortalEntryPointContent::ENTRY_ROUTE]);

		$this->assertSame([], $plan['topics']);
	}//end testNoTopicsAreSeededOntoAnExistingArrangement()

	/**
	 * 🔴 THE SEEDED PAGE IS A DRAFT. A page that appears on a live portal the
	 * moment an app is installed is a page nobody chose to publish, in whatever
	 * wording this file happened to carry.
	 *
	 * @return void
	 */
	public function testTheSeededPageIsADraft(): void {
		$page = $this->content->pagesFor(portal: 'gemeente')[0];

		$this->assertSame('draft', $page['status']);
	}//end testTheSeededPageIsADraft()

	/**
	 * The topics are named for what a resident wants to do. Somebody reporting
	 * a broken street light does not know which directorate owns street
	 * lighting and should not have to.
	 *
	 * @return void
	 */
	public function testTheTopicsAreNamedForTheResidentNotTheDepartment(): void {
		$titles = array_column($this->content->topics(), 'title');

		$this->assertContains('Iets melden in de buurt', $titles);
		foreach ($titles as $title) {
			$this->assertStringNotContainsStringIgnoringCase('afdeling', $title);
			$this->assertStringNotContainsStringIgnoringCase('directie', $title);
		}
	}//end testTheTopicsAreNamedForTheResidentNotTheDepartment()

	/**
	 * The topics are containers only: nothing here invents a catalogue entry,
	 * because that would put a request in front of a citizen that no
	 * municipality decided to offer.
	 *
	 * @return void
	 */
	public function testNoCatalogueEntriesAreInvented(): void {
		foreach ($this->content->topics() as $topic) {
			$this->assertArrayNotHasKey('entries', $topic);
			$this->assertArrayNotHasKey('requests', $topic);
		}
	}//end testNoCatalogueEntriesAreInvented()

	/**
	 * The page carries the layout the entry point renders with, so an editor
	 * sees the catalogue arrangement rather than a blank article.
	 *
	 * @return void
	 */
	public function testThePageCarriesTheCatalogueLayout(): void {
		$this->assertSame(
			PortalEntryPointContent::LAYOUT,
			$this->content->pagesFor(portal: 'gemeente')[0]['layout']
		);
	}//end testThePageCarriesTheCatalogueLayout()
}//end class
