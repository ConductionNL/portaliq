<?php

/**
 * PortalFieldProjector::projectRow(): the identifiers a projection keeps,
 * and their order.
 *
 * @category Tests
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

use OCA\Portaliq\Service\PortalFieldProjector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The preserved identifiers, as recorded.
 */
class PortalFieldProjectorGoldenTest extends TestCase {
	/**
	 * Flat ids follow the projected fields unless declared; an `@self`
	 * envelope shrinks to its ids unless declared, and goes when it has
	 * none or is not a map.
	 *
	 * @return void
	 */
	public function testTheIdentifiersAreAsRecorded(): void {
		$projector = new PortalFieldProjector($this->createMock(LoggerInterface::class));
		$row       = ['uuid' => 'u-1', 'title' => 'T', 'secret' => 's', 'id' => 7, '@self' => ['owner' => 'x', 'uuid' => 'su', 'id' => null, 'register' => 'r']];

		$this->assertSame(
			['title' => 'T', 'id' => 7, 'uuid' => 'u-1', '@self' => ['id' => null, 'uuid' => 'su']],
			$projector->projectRow(row: $row, fields: ['title'])
		);
		$this->assertSame(
			['uuid' => 'u-1', 'title' => 'T', '@self' => $row['@self'], 'id' => 7],
			$projector->projectRow(row: $row, fields: ['uuid', 'title', '@self'])
		);
		$this->assertSame(['title' => 'T'], $projector->projectRow(row: ['title' => 'T', '@self' => ['owner' => 'x']], fields: ['title']));
		$this->assertSame(['title' => 'T'], $projector->projectRow(row: ['title' => 'T', '@self' => 'x'], fields: ['title']));
	}//end testTheIdentifiersAreAsRecorded()
}//end class
