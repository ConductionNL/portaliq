<?php

/**
 * A lookup keyed on a row field, its value standing where a block names a
 * field, and a task title sentence survive the real manifest normaliser
 * (lookup-by-row-field).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/lookup-by-row-field/specs/portal-contribution-contract/spec.md#requirement-a-lookup-may-be-keyed-on-a-field-of-the-row
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/lookup-by-row-field/specs/portal-contribution-contract/spec.md#requirement-a-lookup-may-be-keyed-on-a-field-of-the-row
 */
class LookupByRowFieldTest extends TestCase {
	/**
	 * The lookup learniq declares: the child a row is about.
	 *
	 * @var array<string, string>
	 */
	private const CHILD = [
		'as'         => 'childName',
		'collection' => 'parentChildren',
		'rowField'   => 'learnerRef',
		'matchField' => 'id',
		'valueField' => 'givenName',
	];

	/**
	 * The one block of a page after the real normaliser.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 */
	private function block(array $block): array {
		$out = (new PortalManifestNormaliser())->normalise(
			[
				'collections' => [
					['id' => 'parentChildren', 'schema' => 'learner', 'fields' => ['givenName']],
					['id' => 'parentExcuseRequests', 'schema' => 'excuse-request', 'fields' => ['learnerRef', 'dateFrom', 'reason']],
					['id' => 'parentConferenceTasks', 'schema' => 'conference-round', 'fields' => ['title', 'learnerRef', 'deadline']],
				],
				'actions'     => [],
				'pages'       => [
					[
						'id'     => 'overzicht',
						'label'  => 'Overzicht',
						'blocks' => [$block, ['type' => 'richText', 'markdown' => 'Welkom']],
					],
				],
			]
		);

		return $out['pages'][0]['blocks'][0];
	}//end block()

	/**
	 * The lookup keeps its row field, and its name may title a row.
	 *
	 * @return void
	 */
	public function testARowFieldLookupNamesTheRows(): void {
		$block = $this->block(
			block: [
				'type'        => 'collection',
				'collection'  => 'parentExcuseRequests',
				'display'     => 'rows',
				'dateField'   => 'dateFrom',
				'titleFields' => ['childName', 'reason'],
				'lookups'     => [self::CHILD],
			]
		);
		$this->assertEquals(self::CHILD, $block['lookups'][0]);
		$this->assertSame(['childName', 'reason'], $block['titleFields']);
	}//end testARowFieldLookupNamesTheRows()

	/**
	 * A task sentence is kept when every place names a field or a lookup;
	 * a place that names nothing drops it.
	 *
	 * @return void
	 */
	public function testATaskSentenceNamesOnlyKnownFields(): void {
		$declared = [
			'type'          => 'tasks',
			'collection'    => 'parentConferenceTasks',
			'dueField'      => 'deadline',
			'titleFields'   => ['title'],
			'titleTemplate' => 'Kies een tijd voor het oudergesprek van {childName}',
			'lookups'       => [self::CHILD],
		];
		$this->assertSame('Kies een tijd voor het oudergesprek van {childName}', $this->block(block: $declared)['titleTemplate']);
		$this->assertArrayNotHasKey('titleTemplate', $this->block(block: ['titleTemplate' => 'Van {bsn}'] + $declared));
		$this->assertArrayNotHasKey('titleTemplate', $this->block(block: ['titleTemplate' => 'Geen veld'] + $declared));
	}//end testATaskSentenceNamesOnlyKnownFields()
}//end class
