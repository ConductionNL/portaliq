<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CollectionSchemaLabels;
use OCA\Portaliq\Service\PortalSchemaReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Schema titles fill unlabelled fields, read from `fields` or, without
 * them, from the `columns` list; a collection without a schema or a
 * property without a string title is left alone.
 */
#[CoversClass(CollectionSchemaLabels::class)]
class CollectionSchemaLabelsDirectTest extends TestCase {

	private function labels(int $reads): CollectionSchemaLabels {
		$reader = $this->createMock(PortalSchemaReader::class);
		$reader->expects($this->exactly($reads))->method('readSchema')->with('case')->willReturn(
			[
				'properties' => [
					'title'  => ['title' => 'Onderwerp'],
					'status' => ['title' => ['nl' => 'Status']],
					'owner'  => 'not-a-definition',
					'long'   => ['title' => str_repeat('x', 201)],
				],
			]
		);

		return new CollectionSchemaLabels($reader);
	}//end labels()

	public function testWithoutAReaderCollectionsPassThrough(): void {
		$collections = [['id' => 'a', 'schema' => 'case', 'fields' => ['title']]];
		$this->assertSame($collections, (new CollectionSchemaLabels())->apply($collections));
	}//end testWithoutAReaderCollectionsPassThrough()

	public function testColumnsNameTheFieldsWhenNoFieldListIsGiven(): void {
		$out = $this->labels(1)->apply(
			[
				[
					'id'      => 'cases',
					'schema'  => 'case',
					'columns' => [['field' => 'title'], 'loose', ['field' => 'status'], ['field' => 'owner'], ['field' => 'long'], ['label' => 'no field']],
				],
			]
		);

		$this->assertSame(['title' => ['label' => 'Onderwerp']], $out[0]['fieldConfigs']);
	}//end testColumnsNameTheFieldsWhenNoFieldListIsGiven()

	public function testAnExistingLabelIsKeptAndOtherConfigIsMergedNotReplaced(): void {
		$out = $this->labels(1)->apply(
			[
				[
					'id'           => 'cases',
					'schema'       => 'case',
					'fields'       => ['title', 'status'],
					'fieldConfigs' => ['title' => ['label' => 'Eigen'], 'status' => ['widget' => 'badge']],
				],
			]
		);

		$this->assertSame(['title' => ['label' => 'Eigen'], 'status' => ['widget' => 'badge']], $out[0]['fieldConfigs']);
	}//end testAnExistingLabelIsKeptAndOtherConfigIsMergedNotReplaced()

	public function testACollectionWithoutASchemaSlugIsNotLookedUp(): void {
		$collections = [['id' => 'a', 'schema' => '', 'fields' => ['title']], ['id' => 'b', 'schema' => 7, 'fields' => ['title']]];
		$this->assertSame($collections, $this->labels(0)->apply($collections));
	}//end testACollectionWithoutASchemaSlugIsNotLookedUp()

	public function testTheSchemaIsReadOncePerSlugAcrossCollections(): void {
		$one = ['id' => 'a', 'schema' => 'case', 'fields' => ['title']];
		$out = $this->labels(1)->apply([$one, $one]);
		$this->assertSame('Onderwerp', $out[1]['fieldConfigs']['title']['label']);
	}//end testTheSchemaIsReadOncePerSlugAcrossCollections()
}//end class
