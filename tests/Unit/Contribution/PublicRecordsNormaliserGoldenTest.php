<?php

/**
 * PublicRecordsNormaliser::normalise(): every declared record list kept or
 * dropped, and what is kept of it.
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

use OCA\Portaliq\Contribution\PublicRecordsNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The public record lists, as recorded.
 */
class PublicRecordsNormaliserGoldenTest extends TestCase {
	/**
	 * A provider with one callable pair and methods that may not be called.
	 *
	 * @return object
	 */
	private static function provider(): object {
		return new class {
			public function votes(): array {
				return [];
			}

			public function vote(string $id): array {
				return [$id];
			}

			public static function staticList(): array {
				return [];
			}

			public function twoArgs(string $a, string $b): array {
				return [$a, $b];
			}

			private function hidden(): array {
				return [];
			}
		};
	}//end provider()

	/**
	 * Every entry rule, in order; the first of two equal ids wins.
	 *
	 * @return void
	 */
	public function testEveryEntryIsKeptOrDroppedAsRecorded(): void {
		$good = ['id' => 'votes', 'label' => '  Stemmingen ', 'listProvider' => 'votes', 'recordProvider' => 'vote'];
		$out  = (new PublicRecordsNormaliser())->normalise(
			entries: [
				'junk',
				['id' => 'Votes'] + $good,
				['id' => 7] + $good,
				['label' => ' '] + $good,
				['label' => 3] + $good,
				['listProvider' => 'staticList'] + $good,
				['recordProvider' => 'twoArgs'] + $good,
				['recordProvider' => 'hidden'] + $good,
				['listProvider' => 'getContribution'] + $good,
				['listProvider' => null] + $good,
				$good + ['group' => 'Raad'],
				['label' => 'Second'] + $good,
				['id' => 'other', 'group' => 4] + $good,
			],
			provider: self::provider()
		);

		$this->assertSame(
			[
				['id' => 'votes', 'label' => 'Stemmingen', 'group' => 'Raad', 'listProvider' => 'votes', 'recordProvider' => 'vote'],
				['id' => 'other', 'label' => 'Stemmingen', 'group' => '', 'listProvider' => 'votes', 'recordProvider' => 'vote'],
			],
			$out
		);
		$this->assertSame([], (new PublicRecordsNormaliser())->normalise(entries: [$good], provider: null));
		$this->assertSame([], (new PublicRecordsNormaliser())->normalise(entries: 'votes', provider: self::provider()));
	}//end testEveryEntryIsKeptOrDroppedAsRecorded()
}//end class
