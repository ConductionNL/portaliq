<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalCatalogueReader;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use PHPUnit\Framework\TestCase;

/**
 * portal-intake-form-as-an-object REQ-PIFO-006: the entry point lists what the
 * published catalogue says at the moment it is opened. An entry withdrawn from
 * the catalogue is gone without a portal change, because nothing was kept.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalCatalogueReaderTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	public function testTheEntriesAreGroupedByTheirTopic(): void {
		$this->seedEntry('Wonen', 'Verhuizing doorgeven', 'aanvragen/verhuizing');
		$this->seedEntry('Wonen', 'Parkeervergunning', 'aanvragen/parkeren');
		$this->seedEntry('Afval', 'Grofvuil melden', 'aanvragen/grofvuil');

		$topics = (new PortalCatalogueReader($this->fakeReader()))->topicsFor(portal: 'gemeente-x');

		$this->assertCount(2, $topics);
		$this->assertSame('Wonen', $topics[0]['topic']);
		$this->assertCount(2, $topics[0]['entries']);

	}//end testTheEntriesAreGroupedByTheirTopic()

	public function testAWithdrawnEntryIsGoneWithNoPortalChange(): void {
		$id = $this->seedEntry('Wonen', 'Verhuizing doorgeven', 'aanvragen/verhuizing');
		$reader = new PortalCatalogueReader($this->fakeReader());
		$this->assertCount(1, $reader->topicsFor(portal: 'gemeente-x'));

		$this->rows[$id]['status'] = 'withdrawn';

		$this->assertSame([], $reader->topicsFor(portal: 'gemeente-x'));

	}//end testAWithdrawnEntryIsGoneWithNoPortalChange()

	public function testAnotherPortalsCatalogueIsNotListed(): void {
		$this->seedEntry('Wonen', 'Verhuizing doorgeven', 'aanvragen/verhuizing', portal: 'gemeente-y');

		$this->assertSame([], (new PortalCatalogueReader($this->fakeReader()))->topicsFor(portal: 'gemeente-x'));

	}//end testAnotherPortalsCatalogueIsNotListed()

	public function testAnEntryCarriesTheRouteThatStartsItsForm(): void {
		$this->seedEntry('Wonen', 'Verhuizing doorgeven', 'aanvragen/verhuizing');

		$topics = (new PortalCatalogueReader($this->fakeReader()))->topicsFor(portal: 'gemeente-x');

		$this->assertSame('aanvragen/verhuizing', $topics[0]['entries'][0]['route']);

	}//end testAnEntryCarriesTheRouteThatStartsItsForm()

	/**
	 * Put one published catalogue entry in the fake store.
	 *
	 * @param string $topic The topic it sits under.
	 * @param string $title The entry's title.
	 * @param string $route The route that starts its form.
	 * @param string $portal The portal it is published on.
	 *
	 * @return string The row's uuid.
	 */
	private function seedEntry(string $topic, string $title, string $route, string $portal = 'gemeente-x'): string {
		return $this->seedRow('publication', [
			'portal' => $portal,
			'topic' => $topic,
			'title' => $title,
			'route' => $route,
			'status' => 'published',
		]);
	}//end seedEntry()

}//end class
