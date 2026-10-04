<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * site-mijn-omgeving-components REQ-SMO-020: the page keys `menu: false`,
 * `records`, `perRecord` and `home`, read through the whole manifest
 * normaliser so the wiring from PortalPageResolver is what is tested.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 */
class PageMenuKeysTest extends TestCase {

	/**
	 * The normalised pages of a contribution over two collections.
	 *
	 * @param array<int, mixed> $pages The declared pages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function pages(array $pages): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					['id' => 'parentChildren', 'schema' => 'learner-profile', 'fields' => ['givenName', 'familyName']],
					['id' => 'parentGrades', 'schema' => 'grade-entry'],
				],
				'actions' => [],
				'pages' => $pages,
			]
		);

		return $out['pages'];
	}

	/**
	 * A page with one collection block and the given extra keys.
	 *
	 * @param array<string, mixed> $keys The extra page keys.
	 *
	 * @return array<string, mixed>
	 */
	private function page(array $keys): array {
		return array_merge(['id' => 'p', 'blocks' => [['type' => 'collection', 'collection' => 'parentGrades']]], $keys);
	}

	public function testMenuFalseKeepsTheRoute(): void {
		$pages = $this->pages([$this->page(['menu' => false])]);

		$this->assertSame('p', $pages[0]['id']);
		$this->assertFalse($pages[0]['menu']);
	}

	public function testOnlyFalseAndTrueAreKept(): void {
		$pages = $this->pages([$this->page(['menu' => 'no', 'home' => 'yes'])]);

		$this->assertArrayNotHasKey('menu', $pages[0]);
		$this->assertArrayNotHasKey('home', $pages[0]);

		$pages = $this->pages([$this->page(['home' => true, 'menu' => true])]);
		$this->assertTrue($pages[0]['home']);
		$this->assertArrayNotHasKey('menu', $pages[0]);
	}

	public function testABareRecordsIdIsReadAsACollection(): void {
		$pages = $this->pages(
			[
				$this->page(['id' => 'bare', 'records' => 'parentChildren']),
				$this->page(['id' => 'full', 'records' => ['collection' => 'parentChildren', 'titleFields' => ['givenName', '', 3], 'subtitleFields' => ['familyName']]]),
				$this->page(['id' => 'foreign', 'records' => 'elsewhere']),
			]
		);

		$this->assertSame(['collection' => 'parentChildren'], $pages[0]['records']);
		$this->assertSame(
			['collection' => 'parentChildren', 'titleFields' => ['givenName'], 'subtitleFields' => ['familyName']],
			$pages[1]['records']
		);
		$this->assertArrayNotHasKey('records', $pages[2]);
	}

	/**
	 * A switcher takes its subtitle from one related record: the lookup
	 * names a collection of the contribution and two fields; anything else
	 * is dropped and the switcher stays (site-mijn-omgeving-components
	 * REQ-SMO-026).
	 *
	 * @return void
	 */
	public function testASubtitleLookupNamesACollectionAndTwoFields(): void {
		$lookup = ['collection' => 'parentChildren', 'matchField' => 'learnerRef', 'valueField' => 'cohortName'];
		$pages = $this->pages(
			[
				$this->page(['id' => 'kept', 'records' => ['collection' => 'parentChildren', 'subtitleLookup' => $lookup + ['extra' => 1]]]),
				$this->page(['id' => 'foreign', 'records' => ['collection' => 'parentChildren', 'subtitleLookup' => ['collection' => 'elsewhere'] + $lookup]]),
				$this->page(['id' => 'half', 'records' => ['collection' => 'parentChildren', 'subtitleLookup' => ['valueField' => ''] + $lookup]]),
			]
		);

		$this->assertSame(['collection' => 'parentChildren', 'subtitleLookup' => $lookup], $pages[0]['records']);
		$this->assertSame(['collection' => 'parentChildren'], $pages[1]['records']);
		$this->assertSame(['collection' => 'parentChildren'], $pages[2]['records']);
	}

	public function testPerRecordNeedsItsRecordCollection(): void {
		$pages = $this->pages(
			[
				$this->page(['id' => 'onRecord', 'record' => ['collection' => 'parentChildren'], 'perRecord' => 'parentChildren']),
				$this->page(['id' => 'onRecords', 'records' => 'parentChildren', 'perRecord' => 'parentChildren']),
				$this->page(['id' => 'other', 'record' => ['collection' => 'parentChildren'], 'perRecord' => 'parentGrades']),
				$this->page(['id' => 'noRecord', 'perRecord' => 'parentChildren']),
			]
		);

		$this->assertSame('parentChildren', $pages[0]['perRecord']);
		$this->assertSame('parentChildren', $pages[1]['perRecord']);
		$this->assertArrayNotHasKey('perRecord', $pages[2]);
		$this->assertArrayNotHasKey('perRecord', $pages[3]);
	}
}
