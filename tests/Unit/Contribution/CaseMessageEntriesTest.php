<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://portaliq.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CaseMessageEntries;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The messages about a case reach the page newest first, in one shape, and
 * an entry that says nothing is dropped.
 *
 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
 */
#[CoversClass(CaseMessageEntries::class)]
class CaseMessageEntriesTest extends TestCase {

	public function testMessagesAreHeldToOneShapeNewestFirst(): void {
		$out = (new CaseMessageEntries())->entries(
			[
				['id' => 'q', 'subject' => 'Vraag', 'body' => 'Wanneer?', 'receivedAt' => '2026-11-01T09:00:00+00:00', 'direction' => 'citizen_to_handler', 'senderName' => 'J. Jansen', 'read' => true, 'attachments' => ['a']],
				['id' => 'a', 'subject' => 'Antwoord', 'body' => 'Volgende week.', 'receivedAt' => '2026-11-03T10:00:00+00:00', 'direction' => 'handler_to_citizen', 'senderName' => 'Gemeente', 'read' => 'yes'],
			]
		);

		$this->assertSame(
			[
				['id' => 'a', 'subject' => 'Antwoord', 'body' => 'Volgende week.', 'receivedAt' => '2026-11-03T10:00:00+00:00', 'fromResident' => false, 'senderName' => 'Gemeente', 'read' => false],
				['id' => 'q', 'subject' => 'Vraag', 'body' => 'Wanneer?', 'receivedAt' => '2026-11-01T09:00:00+00:00', 'fromResident' => true, 'senderName' => 'J. Jansen', 'read' => true],
			],
			$out
		);
	}//end testMessagesAreHeldToOneShapeNewestFirst()

	public function testAnEntryThatSaysNothingOrIsNoMapIsDropped(): void {
		$out = (new CaseMessageEntries())->entries(['text', null, ['id' => 'x', 'subject' => '', 'body' => ['nested']], ['id' => 7, 'body' => 'Alleen tekst']]);

		$this->assertCount(1, $out);
		$this->assertSame('7', $out[0]['id']);
		$this->assertSame('', $out[0]['subject']);
		$this->assertSame('Alleen tekst', $out[0]['body']);
		$this->assertFalse($out[0]['fromResident']);
	}//end testAnEntryThatSaysNothingOrIsNoMapIsDropped()

	public function testEqualDatesKeepTheProvidersOrderAndUndatedGoLast(): void {
		$out = (new CaseMessageEntries())->entries(
			[
				['id' => 'undated', 'body' => 'b'],
				['id' => 'first', 'body' => 'b', 'receivedAt' => '2026-11-02'],
				['id' => 'second', 'body' => 'b', 'receivedAt' => '2026-11-02'],
			]
		);

		$this->assertSame(['first', 'second', 'undated'], array_column($out, 'id'));
	}//end testEqualDatesKeepTheProvidersOrderAndUndatedGoLast()
}//end class
