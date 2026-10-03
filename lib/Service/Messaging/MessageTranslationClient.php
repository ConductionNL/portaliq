<?php

/**
 * Message Translation Client
 *
 * Asks hermiq's translation delegate for an AI translation of one message,
 * without depending on hermiq: the engine is looked up by name at runtime, and
 * an absent hermiq, a failing call or an answer that does not say AI made it
 * all come back as "no translation". An unlabelled translation is never kept
 * (decision D24: AI-made translations are visible).
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
 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-messages-are-translated-through-hermiq-only-when-hermiq-is-there-and-labels-its-answer
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Messaging;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Duck-typed client for hermiq's MessageTranslationEngine.
 *
 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-messages-are-translated-through-hermiq-only-when-hermiq-is-there-and-labels-its-answer
 */
class MessageTranslationClient {
	/**
	 * hermiq's engine, by name only. Nextcloud autoloads an app's classes
	 * only while the app is enabled, so `class_exists()` doubles as the
	 * "is hermiq on" check.
	 */
	public const ENGINE_CLASS = 'OCA\\Hermiq\\Service\\MessageTranslationEngine';

	/**
	 * The provenance fields copied from hermiq's answer onto the stored entry.
	 */
	private const PROVENANCE_FIELDS = [
		'sourceLanguage',
		'sourceLanguageDetected',
		'model',
		'originalRef',
		'disclosure',
		'disclosureLanguage',
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves hermiq's engine when it is there.
	 * @param LoggerInterface $logger The logger.
	 * @param string $engineClass The engine's class name; a test names a fake.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $engineClass = self::ENGINE_CLASS,
	) {
	}//end __construct()

	/**
	 * Translate one text, or answer null.
	 *
	 * @param string $text The text as written.
	 * @param string $targetLanguage The reader's language tag.
	 * @param string $originalRef How the original is identified, echoed back by hermiq.
	 *
	 * @return array<string, mixed>|null A `translations` entry: `targetLanguage`, `text`,
	 *                                   `translatedByAi`, the provenance fields and
	 *                                   `translatedAt`; or null when there is no labelled
	 *                                   translation.
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-messages-are-translated-through-hermiq-only-when-hermiq-is-there-and-labels-its-answer
	 */
	public function translate(string $text, string $targetLanguage, string $originalRef): ?array {
		$engine = $this->engine();
		if ($engine === null || $text === '' || $targetLanguage === '') {
			return null;
		}

		try {
			$answer = $engine->translate(sourceText: $text, targetLanguage: $targetLanguage, originalRef: $originalRef);
		} catch (Throwable $e) {
			// An older hermiq without `originalRef` lands here too: no label, no translation.
			$this->logger->info('Portaliq: message translation unavailable', ['reason' => $e->getMessage()]);
			return null;
		}

		return $this->entryFrom(answer: $answer, targetLanguage: $targetLanguage);
	}//end translate()

	/**
	 * The stored entry for a labelled answer, or null.
	 *
	 * @param mixed $answer hermiq's answer.
	 * @param string $targetLanguage The language asked for.
	 *
	 * @return array<string, mixed>|null The entry, or null when the answer is not a labelled translation.
	 */
	private function entryFrom(mixed $answer, string $targetLanguage): ?array {
		if (is_array($answer) === false
			|| ($answer['available'] ?? false) !== true
			|| ($answer['translatedByAi'] ?? null) !== true
			|| is_string($answer['translatedText'] ?? null) === false
			|| $answer['translatedText'] === ''
		) {
			return null;
		}

		$entry = [
			'targetLanguage' => $targetLanguage,
			'text'           => $answer['translatedText'],
			'translatedByAi' => true,
		];
		foreach (self::PROVENANCE_FIELDS as $field) {
			$entry[$field] = $answer[$field] ?? null;
		}

		$entry['sourceLanguage'] = (string)($entry['sourceLanguage'] ?? 'und');
		$entry['translatedAt']   = gmdate('c');
		return $entry;
	}//end entryFrom()

	/**
	 * hermiq's engine, or null when hermiq is not enabled or cannot be built.
	 *
	 * @return object|null
	 */
	private function engine(): ?object {
		if (class_exists($this->engineClass) === false) {
			return null;
		}

		try {
			$engine = $this->container->get($this->engineClass);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: hermiq translation engine not resolvable', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_object($engine) === false || method_exists($engine, 'translate') === false) {
			return null;
		}

		return $engine;
	}//end engine()
}//end class
