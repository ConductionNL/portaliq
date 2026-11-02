<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * collection-intro T1: `intro` on any contributed collection is kept, trimmed,
 * when it holds text and dropped otherwise, through the real manifest
 * normaliser.
 *
 * @spec openspec/changes/archive/2026-10-30-collection-intro/tasks.md#t1
 */
class CollectionIntroKeyTest extends TestCase {
	/**
	 * The first collection after the real normaliser.
	 *
	 * @param array<string, mixed> $extra Keys added to the collection.
	 *
	 * @return array<string, mixed>
	 */
	private function collection(array $extra): array {
		$collection = [
			'id'     => 'mijnZaken',
			'schema' => 'case',
			'kind'   => 'cases',
			'fields' => ['title', 'status'],
		];
		$out        = (new PortalManifestNormaliser())->normalise(
			['collections' => [$collection + $extra], 'actions' => [], 'pages' => []]
		);

		return $out['collections'][0];
	}//end collection()

	/**
	 * An intro with text is kept, trimmed.
	 *
	 * @return void
	 */
	public function testAnIntroWithTextIsKeptTrimmed(): void {
		$this->assertSame(
			expected: 'Hier ziet u al uw lopende zaken.',
			actual: $this->collection(extra: ['intro' => "  Hier ziet u al uw lopende zaken.\n"])['intro']
		);
	}//end testAnIntroWithTextIsKeptTrimmed()

	/**
	 * Markup in an intro is kept as typed; the portal shows it as text.
	 *
	 * @return void
	 */
	public function testAnIntroIsKeptAsTypedWithoutInterpretingMarkup(): void {
		$this->assertSame(
			expected: '<b>Let op</b>',
			actual: $this->collection(extra: ['intro' => '<b>Let op</b>'])['intro']
		);
	}//end testAnIntroIsKeptAsTypedWithoutInterpretingMarkup()

	/**
	 * An empty intro, or one that is not text, is dropped.
	 *
	 * @return void
	 */
	public function testAnEmptyOrNonTextIntroIsDropped(): void {
		foreach (['', '   ', ['nl' => 'Tekst'], 42] as $intro) {
			$this->assertArrayNotHasKey(key: 'intro', array: $this->collection(extra: ['intro' => $intro]));
		}
	}//end testAnEmptyOrNonTextIntroIsDropped()

	/**
	 * A collection without an intro gets none.
	 *
	 * @return void
	 */
	public function testACollectionWithoutAnIntroGetsNone(): void {
		$this->assertArrayNotHasKey(key: 'intro', array: $this->collection(extra: []));
	}//end testACollectionWithoutAnIntroGetsNone()
}//end class
