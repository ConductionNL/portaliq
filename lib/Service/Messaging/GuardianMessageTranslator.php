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
	 *
	 * @return array<int, array<string, mixed>> The same messages, each carrying
	 *                                          `translation` when one applies.
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-translation-work-per-request-is-bounded-and-skips-what-needs-none
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-the-stored-message-keeps-both-texts-and-the-provenance
	 * @spec openspec/changes/news-item-translation/specs/guardian-message-translation/spec.md#requirement-a-news-item-keeps-its-ai-translations-next-to-the-original
	 */
	public function forReader(array $messages, string $readerRef, string $language, string $schema = self::MESSAGE_SCHEMA): array {
		if ($language === '') {
			return $messages;
		}

		$budget = self::NEW_PER_REQUEST;
		foreach ($messages as $index => $message) {
			if ((string)($message['senderRef'] ?? '') === $readerRef || (string)($message['body'] ?? '') === '') {
				continue;
			}

			$entry = $this->storedEntry(message: $message, language: $language);
			if ($entry === null && $budget > 0) {
				$budget--;
				$entry = $this->translateAndStore(message: $message, language: $language, schema: $schema);
			}

			if ($entry !== null && $this->differs(entry: $entry, language: $language) === true) {
				$messages[$index]['translation'] = $entry;
			}
		}//end foreach

		return $messages;
	}//end forReader()

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
	 *
	 * @return array<string, mixed>|null The new entry, or null.
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-the-stored-message-keeps-both-texts-and-the-provenance
	 */
	private function translateAndStore(array $message, string $language, string $schema): ?array {
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

		$stored = $message;
		// Same shape as the read-receipt write: the row minus its envelope.
		unset($stored['translation'], $stored['@self']);
		$stored['translations']   = array_values((array)($message['translations'] ?? []));
		$stored['translations'][] = $entry;
		$this->store->save(schema: $schema, object: $stored, uuid: $id);

		return $entry;
	}//end translateAndStore()

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
