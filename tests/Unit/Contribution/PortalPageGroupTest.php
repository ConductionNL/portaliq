<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * A contributed page may name the menu group it belongs to, so pages from
 * several apps share one heading in the site's left menu
 * (resident-sees-words-not-codes). Driven through PortalManifestNormaliser,
 * the one entry point the registry uses.
 *
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-name-the-menu-group-it-belongs-to
 */
class PortalPageGroupTest extends TestCase {
	/**
	 * Normalise one page that declares the given group.
	 *
	 * @param mixed $group The declared group.
	 *
	 * @return array<string, mixed> The normalised page.
	 */
	private function pageWithGroup(mixed $group): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [['id' => 'vragen', 'schema' => 'question', 'listable' => true]],
				'actions'     => [],
				'pages'       => [
					[
						'id'     => 'vragen',
						'label'  => 'Mijn vragen',
						'group'  => $group,
						'blocks' => [['type' => 'collection', 'collection' => 'vragen']],
					],
				],
			]
		);

		return $out['pages'][0];
	}//end pageWithGroup()

	/**
	 * A string group is kept, trimmed.
	 *
	 * @return void
	 */
	public function testAPageKeepsItsGroup(): void {
		$this->assertSame('Vragen en contact', $this->pageWithGroup(group: '  Vragen en contact ')['group']);
	}//end testAPageKeepsItsGroup()

	/**
	 * A blank, non-string or overlong group is dropped, so the page falls
	 * back to its app's name; the rest of the page stays.
	 *
	 * @return void
	 */
	public function testAMalformedGroupIsDropped(): void {
		foreach (['', '   ', 7, ['Vragen'], null, str_repeat('x', 81)] as $group) {
			$page = $this->pageWithGroup(group: $group);
			$this->assertArrayNotHasKey('group', $page);
			$this->assertSame('Mijn vragen', $page['label']);
		}
	}//end testAMalformedGroupIsDropped()
}//end class
