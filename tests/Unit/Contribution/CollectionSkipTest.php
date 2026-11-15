<?php

/**
 * A collection block keeps a whole-number `skip` through the real manifest
 * normaliser (collection-skip).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/collection-skip/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-leave-out-its-first-rows
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/collection-skip/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-leave-out-its-first-rows
 */
class CollectionSkipTest extends TestCase {
	/**
	 * The collection block after the real normaliser.
	 *
	 * @param mixed $skip The declared skip.
	 *
	 * @return array<string, mixed>
	 */
	private function block(mixed $skip): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [['id' => 'sessions', 'schema' => 'course-session', 'fields' => ['date', 'title']]],
				'actions'     => [],
				'pages'       => [
					[
						'id'     => 'overzicht',
						'label'  => 'Overzicht',
						'blocks' => [
							['type' => 'collection', 'collection' => 'sessions', 'skip' => $skip, 'limit' => 3],
							['type' => 'richText', 'markdown' => 'Welkom'],
						],
					],
				],
			]
		);

		return $out['pages'][0]['blocks'][0];
	}//end block()

	/**
	 * A whole number from 1 to 50 is kept.
	 *
	 * @return void
	 */
	public function testAWholeSkipIsKept(): void {
		$this->assertSame(1, $this->block(skip: 1)['skip']);
		$this->assertSame(50, $this->block(skip: 50)['skip']);
	}//end testAWholeSkipIsKept()

	/**
	 * Zero, too many, a string or a fraction is dropped.
	 *
	 * @return void
	 */
	public function testABadSkipIsDropped(): void {
		foreach ([0, 51, '1', 1.5, -1] as $skip) {
			$this->assertArrayNotHasKey('skip', $this->block(skip: $skip));
		}
	}//end testABadSkipIsDropped()
}//end class
