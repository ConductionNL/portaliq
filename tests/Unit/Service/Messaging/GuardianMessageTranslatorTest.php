<?php

/**
 * GuardianMessageTranslator tests (translated-message-notice).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Messaging
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

namespace OCA\Portaliq\Tests\Unit\Service\Messaging;

use OCA\Portaliq\Service\Messaging\GuardianMessageTranslator;
use OCA\Portaliq\Service\Messaging\MessageStore;
use OCA\Portaliq\Service\Messaging\MessageTranslationClient;
use PHPUnit\Framework\TestCase;

/**
 * Bounded, cached, and never for the reader's own words.
 */
class GuardianMessageTranslatorTest extends TestCase {
	/**
	 * Every save the store received.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>, uuid: string|null}>
	 */
	private array $saved = [];

	/**
	 * Every text the client was asked to translate, with its reference.
	 *
	 * @var array<int, array{text: string, language: string, ref: string}>
	 */
	private array $asked = [];

	/**
	 * A translator over a client that answers `$source`-language translations
	 * (null makes the client answer null) and a store that records saves.
	 *
	 * @param string|null $source The source language the client reports, or null for no translation.
	 *
	 * @return GuardianMessageTranslator
	 */
	private function translator(?string $source = 'nl'): GuardianMessageTranslator {
		$client = $this->getMockBuilder(MessageTranslationClient::class)
			->disableOriginalConstructor()
			->onlyMethods(['translate'])
			->getMock();
		$client->method('translate')->willReturnCallback(
			function (string $text, string $targetLanguage, string $originalRef) use ($source): ?array {
				$this->asked[] = ['text' => $text, 'language' => $targetLanguage, 'ref' => $originalRef];
				if ($source === null) {
					return null;
				}

				return [
					'targetLanguage' => $targetLanguage,
					'text' => '[' . $targetLanguage . '] ' . $text,
					'translatedByAi' => true,
					'sourceLanguage' => $source,
					'originalRef' => $originalRef,
				];
			}
		);

		$store = $this->getMockBuilder(MessageStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['save'])
			->getMock();
		$store->method('save')->willReturnCallback(
			function (string $schema, array $object, ?string $uuid = null): ?string {
				$this->saved[] = ['schema' => $schema, 'object' => $object, 'uuid' => $uuid];
				return $uuid;
			}
		);

		return new GuardianMessageTranslator($client, $store);
	}//end translator()

	/**
	 * A staff message.
	 *
	 * @param string $id The id.
	 * @param array<string, mixed> $extra Extra fields.
	 *
	 * @return array<string, mixed>
	 */
	private function message(string $id, array $extra = []): array {
		return array_merge(['id' => $id, '@self' => ['id' => $id], 'threadRef' => 't1', 'senderRef' => 'teacher-1', 'body' => 'Bericht ' . $id, 'readBy' => ['teacher-1']], $extra);
	}//end message()

	public function testNoLanguageMeansNoWork(): void {
		$messages = [$this->message('m1')];

		$this->assertSame($messages, $this->translator()->forReader($messages, 'guardian-1', ''));
		$this->assertSame([], $this->asked);
	}

	public function testAnUntranslatedMessageIsTranslatedStoredAndAttached(): void {
		$result = $this->translator()->forReader([$this->message('m1')], 'guardian-1', 'tr');

		$this->assertSame([['text' => 'Bericht m1', 'language' => 'tr', 'ref' => 'portaliq:guardianMessage:m1']], $this->asked);
		$this->assertSame('[tr] Bericht m1', $result[0]['translation']['text']);
		$this->assertSame('Bericht m1', $result[0]['body'], 'The original stays in the row.');

		$this->assertCount(1, $this->saved);
		$save = $this->saved[0];
		$this->assertSame('guardianMessage', $save['schema']);
		$this->assertSame('m1', $save['uuid']);
		$this->assertSame('Bericht m1', $save['object']['body'], 'Both texts are stored.');
		$this->assertSame(['teacher-1'], $save['object']['readBy']);
		$this->assertSame('tr', $save['object']['translations'][0]['targetLanguage']);
		$this->assertArrayNotHasKey('@self', $save['object']);
		$this->assertArrayNotHasKey('translation', $save['object'], 'The per-reader view is never stored.');
	}

	public function testAStoredTranslationIsReusedWithoutAskingAgain(): void {
		$cached = ['targetLanguage' => 'tr', 'text' => 'Kayıtlı çeviri', 'translatedByAi' => true, 'sourceLanguage' => 'nl'];
		$other  = ['targetLanguage' => 'ar', 'text' => 'ترجمة', 'translatedByAi' => true, 'sourceLanguage' => 'nl'];

		$result = $this->translator()->forReader([$this->message('m1', ['translations' => [$other, $cached]])], 'guardian-1', 'tr');

		$this->assertSame([], $this->asked);
		$this->assertSame([], $this->saved);
		$this->assertSame('Kayıtlı çeviri', $result[0]['translation']['text']);
	}

	public function testAtMostThreeNewTranslationsPerRequest(): void {
		$messages = [];
		foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $id) {
			$messages[] = $this->message($id);
		}

		$result = $this->translator()->forReader($messages, 'guardian-1', 'ar');

		$this->assertCount(GuardianMessageTranslator::NEW_PER_REQUEST, $this->asked);
		$this->assertSame(3, count(array_filter($result, static fn (array $m): bool => isset($m['translation']))));
		$this->assertArrayNotHasKey('translation', $result[3]);
		$this->assertArrayNotHasKey('translation', $result[4]);
	}

	public function testTheReadersOwnMessageIsNotTranslated(): void {
		$result = $this->translator()->forReader([$this->message('m1', ['senderRef' => 'guardian-1'])], 'guardian-1', 'tr');

		$this->assertSame([], $this->asked);
		$this->assertArrayNotHasKey('translation', $result[0]);
	}

	public function testASameLanguageResultIsStoredButNotShown(): void {
		$result = $this->translator('tr')->forReader([$this->message('m1')], 'guardian-1', 'tr-TR');

		$this->assertCount(1, $this->saved, 'Stored, so the next read asks nobody.');
		$this->assertArrayNotHasKey('translation', $result[0]);
	}

	public function testNoTranslationFromTheClientLeavesTheMessageAsWritten(): void {
		$result = $this->translator(null)->forReader([$this->message('m1')], 'guardian-1', 'tr');

		$this->assertCount(1, $this->asked);
		$this->assertSame([], $this->saved);
		$this->assertArrayNotHasKey('translation', $result[0]);
	}

	public function testAMessageWithoutAnIdIsLeftAlone(): void {
		$message = ['senderRef' => 'teacher-1', 'body' => 'Zonder id'];

		$result = $this->translator()->forReader([$message], 'guardian-1', 'tr');

		$this->assertSame([], $this->asked);
		$this->assertSame([$message], $result);
	}
}
