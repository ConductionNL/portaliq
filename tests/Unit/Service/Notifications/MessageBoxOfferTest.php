<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use OCA\Portaliq\Service\Notifications\MessageBoxOffer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The offer an incoming payload may carry: both fields present and non-blank,
 * trimmed, or nothing at all.
 */
#[CoversClass(MessageBoxOffer::class)]
class MessageBoxOfferTest extends TestCase {
	/**
	 * Both fields, trimmed, make an offer of exactly those two keys.
	 *
	 * @return void
	 */
	public function testBothFieldsTrimmedMakeAnOffer(): void {
		$offer = (new MessageBoxOffer())->from(raw: ['sourceId' => '  src-1 ', 'label' => " Reply \t", 'extra' => 'ignored']);

		$this->assertSame(['sourceId' => 'src-1', 'label' => 'Reply'], $offer);
	}//end testBothFieldsTrimmedMakeAnOffer()

	/**
	 * A non-array, a missing field, a blank field or a non-string field is no
	 * offer at all.
	 *
	 * @return void
	 */
	public function testAnythingIncompleteIsNoOffer(): void {
		$maker = new MessageBoxOffer();

		$this->assertNull($maker->from(raw: 'nope'));
		$this->assertNull($maker->from(raw: null));
		$this->assertNull($maker->from(raw: ['sourceId' => 'src-1']));
		$this->assertNull($maker->from(raw: ['sourceId' => 'src-1', 'label' => '   ']));
		$this->assertNull($maker->from(raw: ['sourceId' => '', 'label' => 'Reply']));
		$this->assertNull($maker->from(raw: ['sourceId' => 5, 'label' => 'Reply']));
	}//end testAnythingIncompleteIsNoOffer()
}//end class
