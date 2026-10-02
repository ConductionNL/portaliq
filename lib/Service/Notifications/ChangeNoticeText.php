<?php

/**
 * Portaliq Change Notice Text
 *
 * The words of a change notice when the contributing app declares them per
 * new value of the rule's field (claim-addressed-change-notices):
 *
 *     "messages": {"acknowledged": {
 *         "subject": {"nl": "Gesprekstijd bevestigd", "en": "Conference time confirmed"},
 *         "body": {"nl": "De leerkracht heeft uw gesprekstijd bevestigd: {startsAt|datetime}, met {teacherName}."}}}
 *
 * A text is a string or a map of language code to string. The portal's
 * language is used, else English, else the first text given, so a notice is
 * always one language. A `{field}` placeholder takes the field of the record
 * AS THE RESIDENT MAY READ IT (the normaliser allowed only projected fields);
 * `{field|datetime}` prints a date-time as `d-m-Y H:i`. A moment with its own
 * offset is printed as entered, a UTC moment in the instance time zone,
 * the same rule the school app uses for its slot labels.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Contribution\NoticeRecipientNormaliser;
use OCP\IDateTimeZone;
use Throwable;

/**
 * Renders a declared change notice in one language.
 *
 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
 */
class ChangeNoticeText {

	/**
	 * How a date-time placeholder prints.
	 *
	 * @var string
	 */
	private const DATETIME_FORMAT = 'd-m-Y H:i';

	/**
	 * Constructor.
	 *
	 * @param IDateTimeZone|null $timeZone The instance time zone; null prints UTC moments in UTC.
	 */
	public function __construct(
		private readonly ?IDateTimeZone $timeZone = null,
	) {
	}//end __construct()

	/**
	 * The subject and body for the new value, or null when the app declares
	 * no message for it.
	 *
	 * @param array<string, mixed> $messages The rule's messages, by value.
	 * @param string               $value    The field's new value.
	 * @param array<string, mixed> $row      The record as the resident may read it.
	 * @param string               $language The portal's language.
	 *
	 * @return array{subject: string, body: string}|null
	 *
	 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
	 */
	public function render(array $messages, string $value, array $row, string $language): ?array {
		$message = ($messages[$value] ?? null);
		if (is_array($message) === false) {
			return null;
		}

		return [
			'subject' => $this->fill(text: $this->pick(texts: ($message['subject'] ?? ''), language: $language), row: $row),
			'body' => $this->fill(text: $this->pick(texts: ($message['body'] ?? ''), language: $language), row: $row),
		];
	}//end render()

	/**
	 * The text in the language, else English, else the first.
	 *
	 * @param mixed  $texts    A string or a language map.
	 * @param string $language The language.
	 *
	 * @return string
	 */
	private function pick(mixed $texts, string $language): string {
		if (is_string($texts) === true) {
			return $texts;
		}

		if (is_array($texts) === false || $texts === []) {
			return '';
		}

		$text = ($texts[$language] ?? $texts['en'] ?? reset($texts));

		return (string)$text;
	}//end pick()

	/**
	 * The text with its placeholders filled from the row.
	 *
	 * @param string               $text The text.
	 * @param array<string, mixed> $row  The row.
	 *
	 * @return string
	 */
	private function fill(string $text, array $row): string {
		return (string)preg_replace_callback(
			NoticeRecipientNormaliser::PLACEHOLDER,
			function (array $match) use ($row): string {
				$value = ($row[$match[1]] ?? null);
				if (is_string($value) === false && is_int($value) === false && is_float($value) === false) {
					return '';
				}

				if (($match[2] ?? '') === '|datetime') {
					return $this->dateTime(value: (string)$value);
				}

				return (string)$value;
			},
			$text
		);
	}//end fill()

	/**
	 * A date-time as `d-m-Y H:i`, or the value as it is when it is no date.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private function dateTime(string $value): string {
		try {
			$moment = new DateTimeImmutable($value);
		} catch (Throwable) {
			return $value;
		}

		if ($moment->getOffset() === 0) {
			$moment = $moment->setTimezone($this->timeZone?->getTimeZone() ?? new DateTimeZone('UTC'));
		}

		return $moment->format(self::DATETIME_FORMAT);
	}//end dateTime()
}//end class
