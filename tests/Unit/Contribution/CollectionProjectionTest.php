<?php

/**
 * Portaliq Collection Projection Test (site-mijn-omgeving-components)
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CollectionProjection;
use PHPUnit\Framework\TestCase;

/**
 * A block may only name the fields its collection projects.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */
class CollectionProjectionTest extends TestCase {


	/**
	 * A collection with fields allows only those; one without allows any but ''.
	 *
	 * @return void
	 */
	public function testProjectsOnlyTheListedFieldsOrAnyWhenNoneAreListed(): void {
		$projection = new CollectionProjection();

		$this->assertTrue(condition: $projection->projects(collection: ['fields' => ['name']], field: 'name'));
		$this->assertFalse(condition: $projection->projects(collection: ['fields' => ['name']], field: 'group'));
		$this->assertTrue(condition: $projection->projects(collection: [], field: 'group'));
		$this->assertFalse(condition: $projection->projects(collection: [], field: ''));
	}//end testProjectsOnlyTheListedFieldsOrAnyWhenNoneAreListed()


	/**
	 * The projected names keep their order and drop the rest.
	 *
	 * @return void
	 */
	public function testProjectedNamesKeepsTheProjectedOnesInOrder(): void {
		$projection = new CollectionProjection();
		$collection = ['fields' => ['name', 'group']];

		$this->assertSame(
			expected: ['group', 'name'],
			actual: $projection->projectedNames(value: ['group', 'secret', 3, 'name'], collection: $collection)
		);
		$this->assertSame(expected: [], actual: $projection->projectedNames(value: 'name', collection: $collection));
	}//end testProjectedNamesKeepsTheProjectedOnesInOrder()
}//end class
