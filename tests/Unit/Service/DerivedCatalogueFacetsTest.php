<?php

/**
 * Derived catalogue facets test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
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

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\DerivedCatalogueFacets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DerivedCatalogueFacets::class)]
class DerivedCatalogueFacetsTest extends TestCase {

	/**
	 * With no facet asked for, the items come back as they went in.
	 *
	 * @return void
	 */
	public function testNoFacetAskedLeavesTheItemsAlone(): void {
		$items = [['id' => 'a', 'title' => 'A']];

		$this->assertSame($items, (new DerivedCatalogueFacets())->apply(items: $items, params: []));
	}//end testNoFacetAskedLeavesTheItemsAlone()
}//end class
