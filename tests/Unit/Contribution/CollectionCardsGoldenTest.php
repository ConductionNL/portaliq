<?php

/**
 * CollectionListKeys: a cards block's title fields and progress figure, as
 * recorded.
 *
 * @category Tests
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

use OCA\Portaliq\Contribution\CollectionListKeys;
use PHPUnit\Framework\TestCase;

/**
 * The cards keys, as recorded.
 */
class CollectionCardsGoldenTest extends TestCase {
	/**
	 * A progress needs a map naming two projected fields; its label is kept
	 * trimmed when it says something; the keys keep their order.
	 *
	 * @return void
	 */
	public function testTheCardsKeysAreAsRecorded(): void {
		$keys       = new CollectionListKeys();
		$collection = ['fields' => ['name', 'done', 'total']];
		$progress   = ['valueField' => 'done', 'totalField' => 'total'];

		$this->assertSame(
			['display' => 'cards', 'titleFields' => ['name'], 'progress' => $progress],
			$keys->collectionKeys(block: ['display' => 'cards', 'titleFields' => ['name'], 'progress' => $progress + ['label' => '  ']], collection: $collection)
		);
		$this->assertSame(['display' => 'cards', 'progress' => $progress], $keys->collectionKeys(block: ['display' => 'cards', 'progress' => $progress + ['label' => 7]], collection: $collection));
		$this->assertSame(['display' => 'cards'], $keys->collectionKeys(block: ['display' => 'cards', 'progress' => 'done'], collection: $collection));
		$this->assertSame(['display' => 'cards'], $keys->collectionKeys(block: ['display' => 'cards', 'progress' => ['valueField' => 'done']], collection: $collection));
		$this->assertSame(
			['display' => 'cards', 'progress' => $progress + ['label' => 'uur']],
			$keys->collectionKeys(block: ['display' => 'cards', 'progress' => ['label' => ' uur', 'totalField' => 'total', 'valueField' => 'done']], collection: $collection)
		);
	}//end testTheCardsKeysAreAsRecorded()
}//end class
