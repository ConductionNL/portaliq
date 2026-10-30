<?php

/**
 * Example data for the DqZaak board: Anouk Bakker, a colleague of the
 * handler, follows the Lindelaan Woo case, so the case page shows AB among
 * its followers.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-anouk-follows-woo/spec.md#requirement-anouk-bakker-follows-the-lindelaan-woo-case
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Anouk follows the Woo case the DqZaak boards open.
 *
 * @spec openspec/specs/example-set-anouk-follows-woo/spec.md#requirement-anouk-bakker-follows-the-lindelaan-woo-case
 */
class ZuiddrechtAnoukFollowsWooCaseTest extends TestCase {

	/**
	 * One watcher row, Anouk on woo-fietspad, with notifications on.
	 *
	 * @return void
	 */
	public function testAnoukFollowsTheWooCase(): void {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		$watchers = array_column($set['watchers'] ?? [], 'data', 'key');
		$this->assertArrayHasKey(key: 'anouk-volgt-woo-fietspad', array: $watchers);
		$this->assertSame(
			expected: [
				'user'       => '{{cast:anouk.uid}}',
				'objectUuid' => '{{object:woo-fietspad.id}}',
				'register'   => 'dossiq',
				'schema'     => 'case',
				'notify'     => true,
			],
			actual: $watchers['anouk-volgt-woo-fietspad']
		);
	}//end testAnoukFollowsTheWooCase()
}//end class
