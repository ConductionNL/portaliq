<?php

/**
 * Guardian Message Translator
 *
 * Shows a thread's messages in the reader's language. For each message the
 * reader did not send, it reuses a stored translation for that language or
 * asks hermiq for one (at most three new ones per request), stores it on the
 * message next to the original body, and attaches it to the row as
 * `translation` so the portal renders the AI notice (decision D24).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Messaging
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
 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-translation-work-per-request-is-bounded-and-skips-what-needs-none
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Messaging;

/**
 * Attaches (and stores) AI translations for one reader.
 *
 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-translation-work-per-request-is-bounded-and-skips-what-needs-none
 */
class GuardianMessageTranslator {
	/**
	 * The schema the messages live in.
	 */
	private const MESSAGE_SCHEMA = 'guardianMessage';

	/**
	 * How many new translations one request may ask hermiq for. A provider
	 * call takes seconds; the rest follow on the next load.
	 */
	public const NEW_PER_REQUEST = 3;

	/**
	 * Constructor.
	 *
	 * @param MessageTranslationClient $client The duck-typed hermiq client.
	 * @param MessageStore $store Writes the translation onto the message.
	 */
	public function __construct(
		private readonly MessageTranslationClient $client,
		private readonly MessageStore $store,
	) {
	}//end __construct()

	/**
	 * The messages as the reader should see them.
	 *
	 * @param array<int, array<string, mixed>> $messages The thread's messages, already authorised.
	 * @param string $readerRef The reader's own subjectRef.
	 * @param string $language The reader's `messageLanguage`, '' for as written.
	 * @param string $schema The schema the rows live in and are stored back to:
	 *                       `guardianMessage`, or `newsItem` for the news feed.
	 * @param string $titleField A field translated with the body into the same
	 *                           entry as `title` (`title` for news), '' for none.
	 *
	 * @return array<int, array<string, mixed>> The same messages, each carrying
	 *                                          `translation` when one applies.
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-translation-work-per-request-is-bounded-and-skips-what-needs-none
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-the-stored-message-keeps-both-texts-and-the-provenance
	 * @spec openspec/changes/news-item-translation/specs/guardian-message-translation/spec.md#requirement-a-news-item-keeps-its-ai-translations-next-to-the-original
	 * @spec openspec/changes/news-title-and-newsletter-translation/specs/guardian-message-translation/spec.md#requirement-a-news-title-is-translated-with-its-body
	 */
	public function forReader(
		array $messages,
		string $readerRef,
		string $language,
		string $schema = self::MESSAGE_SCHEMA,
		string $titleField = ''
	): array {
		if ($language === '') {
			return $messages;
		}

		$budget = self::NEW_PER_REQUEST;
		foreach ($messages as $index => $message) {
			if ((string)($message['senderRef'] ?? '') === $readerRef || (string)($message['body'] ?? '') === '') {
				continue;
			}

			$entry = $this->entryFor(message: $message, language: $language, schema: $schema, titleField: $titleField, budget: $budget);
			if ($entry !== null && $this->differs(entry: $entry, language: $language) === true) {
				$messages[$index]['translation'] = $entry;
			}
		}

		return $messages;
	}//end forReader()

	/**
	 * The reader's entry for one row: the stored one, a new one while the
	 * budget lasts, or the stored one with its title added (a row translated
	 * before titles were). Each call to hermiq spends one from the budget.
	 *
	 * @param array<string, mixed> $message The row.
	 * @param string $language The reader's language.
	 * @param string $schema The schema the row is stored back to.
	 * @param string $titleField The title field, '' for none.
	 * @param int $budget The remaining per-request budget, spent in place.
	 *
	 * @return array<string, mixed>|null The entry, or null.
	 */
	private function entryFor(array $message, string $language, string $schema, string $titleField, int &$budget): ?array {
		$entry = $this->storedEntry(message: $message, language: $language);
		if ($budget <= 0) {
			return $entry;
		}

		if ($entry === null) {
			$budget--;
			return $this->translateAndStore(message: $message, language: $language, schema: $schema, titleField: $titleField);
		}

		if ($this->lacksTitle(entry: $entry, message: $message, titleField: $titleField, language: $language) === true) {
			$budget--;
			return $this->addTitle(message: $message, entry: $entry, schema: $schema, titleField: $titleField);
		}

		return $entry;
	}//end entryFor()

	/**
	 * The stored translation of this message for this language, if any.
	 *
	 * @param array<string, mixed> $message The message.
	 * @param string $language The reader's language.
	 *
	 * @return array<string, mixed>|null
	 */
	private function storedEntry(array $message, string $language): ?array {
		foreach ((array)($message['translations'] ?? []) as $entry) {
			if (is_array($entry) === true && (string)($entry['targetLanguage'] ?? '') === $language) {
				return $entry;
			}
		}

		return null;
	}//end storedEntry()

	/**
	 * Ask hermiq, and store a labelled answer on the message next to its body.
	 *
	 * @param array<string, mixed> $message The message.
	 * @param string $language The reader's language.
	 * @param string $schema The schema the row is stored back to.
	 * @param string $titleField The field translated into the entry's `title`, '' for none.
	 *
	 * @return array<string, mixed>|null The new entry, or null.
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-the-stored-message-keeps-both-texts-and-the-provenance
	 * @spec openspec/changes/news-title-and-newsletter-translation/specs/guardian-message-translation/spec.md#requirement-a-news-title-is-translated-with-its-body
	 */
	private function translateAndStore(array $message, string $language, string $schema, string $titleField = ''): ?array {
		$id = $this->store->rowId(row: $message);
		if ($id === null) {
			return null;
		}

		$entry = $this->client->translate(
			text: (string)$message['body'],
			targetLanguage: $language,
			originalRef: 'portaliq:' . $schema . ':' . $id
		);
		if ($entry === null) {
			return null;
		}

		$entry = $this->withTitle(entry: $entry, message: $message, schema: $schema, id: $id, titleField: $titleField);

		$entries   = array_values((array)($message['translations'] ?? []));
		$entries[] = $entry;
		$this->storeEntries(message: $message, entries: $entries, schema: $schema, id: $id);

		return $entry;
	}//end translateAndStore()

	/**
	 * Whether a stored entry still needs the title a news item has.
	 *
	 * @param array<string, mixed> $entry The stored entry.
	 * @param array<string, mixed> $message The row.
	 * @param string $titleField The title field, '' for none.
	 * @param string $language The reader's language.
	 *
	 * @return bool
	 */
	private function lacksTitle(array $entry, array $message, string $titleField, string $language): bool {
		return $titleField !== ''
			&& (string)($message[$titleField] ?? '') !== ''
			&& (string)($entry['title'] ?? '') === ''
			&& $this->differs(entry: $entry, language: $language) === true;
	}//end lacksTitle()

	/**
	 * Translate the title into a stored entry and store the entry in place.
	 *
	 * @param array<string, mixed> $message The row.
	 * @param array<string, mixed> $entry The stored entry without a title.
	 * @param string $schema The schema the row is stored back to.
	 * @param string $titleField The title field.
	 *
	 * @return array<string, mixed> The entry, with its title when hermiq labelled one.
	 *
	 * @spec openspec/changes/news-title-and-newsletter-translation/specs/guardian-message-translation/spec.md#requirement-a-news-title-is-translated-with-its-body
	 */
	private function addTitle(array $message, array $entry, string $schema, string $titleField): array {
		$id = $this->store->rowId(row: $message);
		if ($id === null) {
			return $entry;
		}

		$titled = $this->withTitle(entry: $entry, message: $message, schema: $schema, id: $id, titleField: $titleField);
		if (isset($titled['title']) === false) {
			return $entry;
		}

		$entries = [];
		foreach ((array)($message['translations'] ?? []) as $stored) {
			if (is_array($stored) === true && (string)($stored['targetLanguage'] ?? '') === (string)($entry['targetLanguage'] ?? '')) {
				$stored = $titled;
			}

			$entries[] = $stored;
		}

		$this->storeEntries(message: $message, entries: $entries, schema: $schema, id: $id);
		return $titled;
	}//end addTitle()

	/**
	 * The entry with the translated title, when the row has one and hermiq
	 * labels its answer. The title shares the body's entry, so one notice and
	 * one provenance cover both; an unlabelled answer adds nothing.
	 *
	 * @param array<string, mixed> $entry The body's entry.
	 * @param array<string, mixed> $message The row.
	 * @param string $schema The schema, for the original reference.
	 * @param string $id The row id.
	 * @param string $titleField The title field, '' for none.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function withTitle(array $entry, array $message, string $schema, string $id, string $titleField): array {
		$title = (string)($message[$titleField] ?? '');
		if ($titleField === '' || $title === '') {
			return $entry;
		}

		$answer = $this->client->translate(
			text: $title,
			targetLanguage: (string)$entry['targetLanguage'],
			originalRef: 'portaliq:' . $schema . ':' . $id
		);
		if ($answer !== null) {
			$entry['title'] = (string)$answer['text'];
		}

		return $entry;
	}//end withTitle()

	/**
	 * Store the row with these translations, the row minus its envelope.
	 *
	 * @param array<string, mixed> $message The row as read.
	 * @param array<int, mixed> $entries The translations to store.
	 * @param string $schema The schema.
	 * @param string $id The row id.
	 *
	 * @return void
	 */
	private function storeEntries(array $message, array $entries, string $schema, string $id): void {
		$stored = $message;
		// Same shape as the read-receipt write: the row minus its envelope.
		unset($stored['translation'], $stored['@self']);
		$stored['translations'] = $entries;
		$this->store->save(schema: $schema, object: $stored, uuid: $id);
	}//end storeEntries()

	/**
	 * Whether the translation is into a different language than the source,
	 * compared on the primary subtag. A Dutch message for a Dutch reader is
	 * kept in the cache but shown as written.
	 *
	 * @param array<string, mixed> $entry The translation entry.
	 * @param string $language The reader's language.
	 *
	 * @return bool
	 */
	private function differs(array $entry, string $language): bool {
		$source = strtolower(explode('-', (string)($entry['sourceLanguage'] ?? 'und'))[0]);
		return $source !== strtolower(explode('-', $language)[0]);
	}//end differs()
}//end class
