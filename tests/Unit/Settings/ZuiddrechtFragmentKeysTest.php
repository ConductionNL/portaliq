<?php

/**
 * The fragment declares each part once. JSON keeps only the last of two equal
 * keys, so a second "notes" part silently drops the first one's rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-set-sanne-cases/spec.md#requirement-sannes-lindelaan-case-is-the-case-the-boards-draw-and-her-cases-name-her
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * One top-level key per part.
 */
class ZuiddrechtFragmentKeysTest extends TestCase {

	/**
	 * No top-level key appears twice in the file.
	 *
	 * @return void
	 */
	public function testEveryPartIsDeclaredOnce(): void {
		$text = (string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json');
		preg_match_all('/^\t"([A-Za-z$]+)":/m', $text, $matches);

		$this->assertSame([], array_keys(array_filter(array_count_values($matches[1]), static fn (int $count): bool => $count > 1)));
	}//end testEveryPartIsDeclaredOnce()
}//end class
