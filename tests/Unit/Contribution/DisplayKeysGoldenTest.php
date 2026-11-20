<?php

/**
 * DisplayKeys::keys(): the display, its single-field keys and its text keys,
 * as recorded.
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

use OCA\Portaliq\Contribution\DisplayKeys;
use PHPUnit\Framework\TestCase;

/**
 * The display keys of a collection block, as recorded.
 */
class DisplayKeysGoldenTest extends TestCase {
	/**
	 * Unknown displays keep nothing; the fields keep only what the
	 * collection projects (anything when it lists no fields, nothing when it
	 * is unknown); a text is trimmed and kept up to 80 characters.
	 *
	 * @return void
	 */
	public function testTheDisplayFieldsAndTextsAreAsRecorded(): void {
		$keys = new DisplayKeys();
		$this->assertSame([], $keys->keys(block: ['display' => 7], collection: []));
		$this->assertSame([], $keys->keys(block: ['display' => 'table'], collection: []));
		$this->assertSame([], $keys->keys(block: [], collection: []));

		$this->assertSame(
			['display' => 'cards', 'statusField' => 'status', 'soonLabel' => 'Binnenkort'],
			$keys->keys(block: ['display' => 'cards', 'soonLabel' => '  Binnenkort ', 'statusField' => 'status', 'noteField' => 'secret', 'soonField' => ''], collection: ['fields' => ['status', 'note']])
		);
		$this->assertSame(
			['display' => 'cards', 'statusField' => 'status', 'noteField' => 'secret'],
			$keys->keys(block: ['display' => 'cards', 'soonLabel' => str_repeat('s', 81), 'statusField' => 'status', 'noteField' => 'secret'], collection: ['fields' => 'all'])
		);
		$this->assertSame(
			['display' => 'bars', 'noteLabel' => str_repeat('n', 80)],
			$keys->keys(block: ['display' => 'bars', 'noteLabel' => str_repeat('n', 80), 'labelField' => 'vak'], collection: null)
		);
		$this->assertSame(['display' => 'bars'], $keys->keys(block: ['display' => 'bars', 'noteLabel' => '   '], collection: []));
		$this->assertSame(['display' => 'chips', 'labelField' => 'vak'], $keys->keys(block: ['display' => 'chips', 'labelField' => 'vak', 'valuesField' => 3], collection: []));
	}//end testTheDisplayFieldsAndTextsAreAsRecorded()
}//end class
