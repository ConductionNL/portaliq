<?php

/**
 * PublicDetailShape: the action a detail page places, kept or dropped, and
 * its count bounds.
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

use OCA\Portaliq\Contribution\PublicDetailShape;
use PHPUnit\Framework\TestCase;

/**
 * The placed action, as recorded.
 */
class PublicDetailActionGoldenTest extends TestCase {
	/**
	 * The action a detail answer keeps, or null.
	 *
	 * @param mixed $action The declared action.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function action(mixed $action): ?array {
		return ((new PublicDetailShape())->detail(['title' => 'T', 'action' => $action])['action'] ?? null);
	}//end action()

	/**
	 * Every dropped action, every count bound and the sign-in default.
	 *
	 * @return void
	 */
	public function testThePlacedActionIsAsRecorded(): void {
		foreach (['enrol', ['label' => 'L'], ['id' => '1enrol', 'label' => 'L'], ['id' => str_repeat('a', 65), 'label' => 'L'], ['id' => 'enrol'], ['id' => 'en rol', 'label' => 'L']] as $bad) {
			$this->assertNull(self::action($bad), json_encode($bad));
		}

		$this->assertSame(['id' => 'enrol', 'label' => 'L', 'requiresSignIn' => true], self::action(['id' => 'enrol', 'label' => 'L', 'countMax' => 5]));
		$this->assertSame(['id' => 'a-b_1', 'label' => 'L', 'requiresSignIn' => false], self::action(['id' => 'a-b_1', 'label' => 'L', 'requiresSignIn' => false]));
		$this->assertTrue(self::action(['id' => 'enrol', 'label' => 'L', 'requiresSignIn' => 'no'])['requiresSignIn']);

		$counts = [];
		foreach ([1, 100, 0, 101, '5', null, 42] as $max) {
			$declared = ['id' => 'enrol', 'label' => 'L', 'countLabel' => ' Aantal '];
			if ($max !== null) {
				$declared['countMax'] = $max;
			}

			$counts[] = self::action($declared);
		}

		$this->assertSame(['countLabel' => 'Aantal', 'countMax' => 1], array_slice($counts[0], 3));
		$this->assertSame([1, 100, 20, 20, 20, 20, 42], array_column($counts, 'countMax'));
	}//end testThePlacedActionIsAsRecorded()
}//end class
