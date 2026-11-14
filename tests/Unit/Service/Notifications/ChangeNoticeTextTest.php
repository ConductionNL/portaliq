<?php

/**
 * Unit tests for the text of a change notice.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use DateTimeZone;
use OCA\Portaliq\Service\Notifications\ChangeNoticeText;
use OCP\IDateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A change notice picks the message declared for the new value, in the
 * resident's language, and fills its placeholders from the record.
 */
#[CoversClass(ChangeNoticeText::class)]
class ChangeNoticeTextTest extends TestCase {

	/**
	 * A value without a declared message renders nothing.
	 *
	 * @return void
	 */
	public function testAValueWithoutAMessageRendersNothing(): void {
		$text = new ChangeNoticeText();

		$this->assertNull($text->render(messages: [], value: 'closed', row: [], language: 'nl'));
		$this->assertNull($text->render(messages: ['closed' => 'Closed'], value: 'closed', row: [], language: 'nl'));
	}//end testAValueWithoutAMessageRendersNothing()

	/**
	 * The resident's language wins, then English, then the first text declared.
	 *
	 * @return void
	 */
	public function testTheLanguageFallsBackToEnglishThenTheFirstText(): void {
		$text     = new ChangeNoticeText();
		$messages = [
			'closed' => [
				'subject' => ['nl' => 'Gesloten', 'en' => 'Closed'],
				'body'    => ['de' => 'Geschlossen', 'fr' => 'Fermé'],
			],
		];

		$this->assertSame(['subject' => 'Gesloten', 'body' => 'Geschlossen'], $text->render(messages: $messages, value: 'closed', row: [], language: 'nl'));
		$this->assertSame(['subject' => 'Closed', 'body' => 'Fermé'], $text->render(messages: $messages, value: 'closed', row: [], language: 'fr'));
	}//end testTheLanguageFallsBackToEnglishThenTheFirstText()

	/**
	 * A plain string is used as is; an empty, missing or non-text value gives an empty text.
	 *
	 * @return void
	 */
	public function testPlainStringsAndUnusableTexts(): void {
		$text = new ChangeNoticeText();

		$this->assertSame(
			['subject' => 'Status changed', 'body' => ''],
			$text->render(messages: ['x' => ['subject' => 'Status changed', 'body' => []]], value: 'x', row: [], language: 'nl')
		);
		$this->assertSame(
			['subject' => '', 'body' => ''],
			$text->render(messages: ['x' => ['subject' => 42]], value: 'x', row: [], language: 'nl')
		);
	}//end testPlainStringsAndUnusableTexts()

	/**
	 * Placeholders take the row's scalar values; missing and non-scalar values become empty.
	 *
	 * @return void
	 */
	public function testPlaceholdersAreFilledFromTheRow(): void {
		$text     = new ChangeNoticeText();
		$messages = ['x' => ['subject' => '{title} #{number} ({score})', 'body' => '[{missing}][{tags}][{flag}] {not a placeholder}']];
		$row      = ['title' => 'Permit', 'number' => 12, 'score' => 1.5, 'tags' => ['a'], 'flag' => true];

		$this->assertSame(
			['subject' => 'Permit #12 (1.5)', 'body' => '[][][] {not a placeholder}'],
			$text->render(messages: $messages, value: 'x', row: $row, language: 'en')
		);
	}//end testPlaceholdersAreFilledFromTheRow()

	/**
	 * A UTC moment is shown in the instance time zone; a moment with its own offset keeps it.
	 *
	 * @return void
	 */
	public function testDatetimePlaceholdersUseTheInstanceTimeZoneForUtc(): void {
		$timeZone = $this->createMock(IDateTimeZone::class);
		$timeZone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$text     = new ChangeNoticeText(timeZone: $timeZone);
		$messages = ['x' => ['subject' => '{at|datetime}', 'body' => '{local|datetime} / {bad|datetime}']];
		$row      = ['at' => '2026-03-01T12:30:00Z', 'local' => '2026-07-01T09:05:00+02:00', 'bad' => 'not a date'];

		$this->assertSame(
			['subject' => '01-03-2026 13:30', 'body' => '01-07-2026 09:05 / not a date'],
			$text->render(messages: $messages, value: 'x', row: $row, language: 'nl')
		);
	}//end testDatetimePlaceholdersUseTheInstanceTimeZoneForUtc()

	/**
	 * Without an instance time zone a UTC moment is shown in UTC.
	 *
	 * @return void
	 */
	public function testWithoutATimeZoneUtcStaysUtc(): void {
		$text = new ChangeNoticeText();

		$this->assertSame(
			['subject' => '01-03-2026 12:30', 'body' => ''],
			$text->render(messages: ['x' => ['subject' => '{at|datetime}']], value: 'x', row: ['at' => '2026-03-01T12:30:00+00:00'], language: 'nl')
		);
	}//end testWithoutATimeZoneUtcStaysUtc()
}//end class
