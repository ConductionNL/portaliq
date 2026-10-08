<?php

/**
 * Tests for the portals a shared block write touches (site-shared-page-blocks).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\SharedBlockPortals;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\Cms\SharedBlockPortals
 */
class SharedBlockPortalsTest extends TestCase {
	private const PORTALS = [
		['slug' => 'inwoners', 'organisation' => 'gemeente-voorbeeld'],
		['slug' => 'bedrijven', 'organisation' => 'gemeente-voorbeeld'],
		['slug' => 'noord', 'organisation' => 'gemeente-noord'],
	];

	/**
	 * A block write names every portal of its organisation and no other.
	 *
	 * @return void
	 */
	public function testABlockWriteNamesThePortalsOfItsOrganisation(): void {
		$slugs = SharedBlockPortals::slugsFor(['organisation' => 'gemeente-voorbeeld', 'title' => 'Contact', 'widgets' => []], self::PORTALS);

		$this->assertSame(['inwoners', 'bedrijven'], $slugs);
	}//end testABlockWriteNamesThePortalsOfItsOrganisation()

	/**
	 * Anything that is not a block names nothing: a portal's own objects, or an organisation's account.
	 *
	 * @return void
	 */
	public function testOtherWritesNameNothing(): void {
		$this->assertSame([], SharedBlockPortals::slugsFor(['organisation' => 'gemeente-voorbeeld', 'displayName' => 'A'], self::PORTALS));
		$this->assertSame([], SharedBlockPortals::slugsFor(['portal' => 'inwoners', 'organisation' => 'gemeente-voorbeeld', 'widgets' => []], self::PORTALS));
		$this->assertSame([], SharedBlockPortals::slugsFor(['widgets' => []], self::PORTALS));
	}//end testOtherWritesNameNothing()
}//end class
