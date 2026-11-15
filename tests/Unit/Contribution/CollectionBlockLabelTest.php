<?php

/**
 * A collection block keeps its own heading through the real manifest
 * normaliser (collection-block-label).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/collection-block-label/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-keeps-its-own-heading
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/collection-block-label/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-keeps-its-own-heading
 */
class CollectionBlockLabelTest extends TestCase {
	/**
	 * The collection block after the real normaliser.
	 *
	 * @param mixed $label The declared label.
	 *
	 * @return array<string, mixed>
	 */
	private function block(mixed $label): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [['id' => 'studentGrades', 'schema' => 'grade-entry', 'fields' => ['courseName', 'value']]],
				'actions'     => [],
				'pages'       => [
					[
						'id'     => 'overzicht',
						'label'  => 'Overzicht',
						'blocks' => [
							['type' => 'collection', 'collection' => 'studentGrades', 'label' => $label, 'limit' => 3],
							['type' => 'richText', 'markdown' => 'Welkom'],
						],
					],
				],
			]
		);

		return $out['pages'][0]['blocks'][0];
	}//end block()

	/**
	 * A label is kept, trimmed.
	 *
	 * @return void
	 */
	public function testALabelIsKept(): void {
		$this->assertSame('Laatste cijfers', $this->block(label: '  Laatste cijfers ')['label']);
	}//end testALabelIsKept()

	/**
	 * A blank, overlong or non-string label is dropped.
	 *
	 * @return void
	 */
	public function testABadLabelIsDropped(): void {
		foreach (['   ', str_repeat('x', 121), ['nl' => 'Cijfers'], 3] as $label) {
			$this->assertArrayNotHasKey('label', $this->block(label: $label));
		}
	}//end testABadLabelIsDropped()
}//end class
