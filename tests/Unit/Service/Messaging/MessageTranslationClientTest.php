<?php

/**
 * MessageTranslationClient tests (translated-message-notice).
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
 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-messages-are-translated-through-hermiq-only-when-hermiq-is-there-and-labels-its-answer
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Messaging;

use OCA\Portaliq\Service\Messaging\MessageTranslationClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for hermiq's engine: answers what the test hands it and records
 * the named arguments it was called with.
 */
class FakeTranslationEngine {
	/**
	 * The answer, or an exception to throw.
	 *
	 * @var array<string, mixed>|RuntimeException
	 */
	public array|RuntimeException $answer = [];

	/**
	 * Every call's arguments.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $calls = [];

	/**
	 * The engine's translate(), with the same named parameters hermiq's has.
	 *
	 * @param string $sourceText The text.
	 * @param string $targetLanguage The target tag.
	 * @param string $originalRef The original's reference.
	 *
	 * @return array<string, mixed>
	 */
	public function translate(string $sourceText, string $targetLanguage, string $originalRef = ''): array {
		$this->calls[] = ['sourceText' => $sourceText, 'targetLanguage' => $targetLanguage, 'originalRef' => $originalRef];
		if ($this->answer instanceof RuntimeException) {
			throw $this->answer;
		}

		return $this->answer;
	}
}

/**
 * The client keeps a translation only when hermiq is there and labels it.
 */
class MessageTranslationClientTest extends TestCase {
	/**
	 * A client whose container hands out `$engine`.
	 *
	 * @param object $engine The engine to hand out.
	 * @param string $engineClass The class name the client looks for.
	 *
	 * @return MessageTranslationClient
	 */
	private function client(object $engine, string $engineClass = FakeTranslationEngine::class): MessageTranslationClient {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($engine);
		return new MessageTranslationClient($container, $this->createMock(LoggerInterface::class), $engineClass);
	}//end client()

	/**
	 * A labelled answer from hermiq.
	 *
	 * @return array<string, mixed>
	 */
	private function labelled(): array {
		return [
			'available' => true,
			'translatedText' => 'Okul yarın kapalı.',
			'translatedByAi' => true,
			'sourceLanguage' => 'nl',
			'sourceLanguageDetected' => true,
			'targetLanguage' => 'tr',
			'model' => 'nextcloud',
			'originalRef' => 'portaliq:guardianMessage:m1',
			'disclosure' => 'Yapay zekâ ile çevrildi. Orijinal dil: Nederlands. Bu çeviri hatalar içerebilir.',
			'disclosureLanguage' => 'tr',
			'provider' => 'nextcloud',
		];
	}//end labelled()

	public function testNoHermiqMeansNoTranslationAndNoCall(): void {
		$engine = new FakeTranslationEngine();
		$client = $this->client($engine, 'OCA\\Hermiq\\Service\\NotInstalledEngine');

		$this->assertNull($client->translate('De school is morgen dicht.', 'tr', 'portaliq:guardianMessage:m1'));
		$this->assertSame([], $engine->calls);
	}

	public function testALabelledAnswerBecomesAStoredEntryWithItsProvenance(): void {
		$engine = new FakeTranslationEngine();
		$engine->answer = $this->labelled();

		$entry = $this->client($engine)->translate('De school is morgen dicht.', 'tr', 'portaliq:guardianMessage:m1');

		$this->assertSame(
			[['sourceText' => 'De school is morgen dicht.', 'targetLanguage' => 'tr', 'originalRef' => 'portaliq:guardianMessage:m1']],
			$engine->calls
		);
		$this->assertNotNull($entry);
		$this->assertSame('tr', $entry['targetLanguage']);
		$this->assertSame('Okul yarın kapalı.', $entry['text']);
		$this->assertTrue($entry['translatedByAi']);
		$this->assertSame('nl', $entry['sourceLanguage']);
		$this->assertTrue($entry['sourceLanguageDetected']);
		$this->assertSame('nextcloud', $entry['model']);
		$this->assertSame('portaliq:guardianMessage:m1', $entry['originalRef']);
		$this->assertSame('tr', $entry['disclosureLanguage']);
		$this->assertStringStartsWith('Yapay zekâ', $entry['disclosure']);
		$this->assertNotSame('', $entry['translatedAt']);
		$this->assertArrayNotHasKey('provider', $entry, 'Only the provenance fields are kept.');
	}

	public function testAnUnlabelledAnswerIsDropped(): void {
		$engine = new FakeTranslationEngine();
		$answer = $this->labelled();
		unset($answer['translatedByAi']);
		$engine->answer = $answer;

		$this->assertNull($this->client($engine)->translate('Hallo', 'tr', 'ref'));
	}

	public function testAnUnavailableAnswerIsDropped(): void {
		$engine = new FakeTranslationEngine();
		$engine->answer = ['available' => false, 'reason' => 'feature-not-enabled', 'translatedByAi' => false];

		$this->assertNull($this->client($engine)->translate('Hallo', 'tr', 'ref'));
	}

	public function testAThrowingEngineIsNoTranslation(): void {
		$engine = new FakeTranslationEngine();
		$engine->answer = new RuntimeException('Unknown named parameter $originalRef');

		$this->assertNull($this->client($engine)->translate('Hallo', 'tr', 'ref'));
	}

	public function testAnEngineWithoutTranslateOrAnEmptyInputIsNoTranslation(): void {
		$this->assertNull($this->client(new \stdClass(), \stdClass::class)->translate('Hallo', 'tr', 'ref'));

		$engine = new FakeTranslationEngine();
		$engine->answer = $this->labelled();
		$this->assertNull($this->client($engine)->translate('', 'tr', 'ref'));
		$this->assertNull($this->client($engine)->translate('Hallo', '', 'ref'));
		$this->assertSame([], $engine->calls);
	}

	public function testTheDefaultEngineIsHermiqsByName(): void {
		$this->assertSame('OCA\\Hermiq\\Service\\MessageTranslationEngine', MessageTranslationClient::ENGINE_CLASS);
	}
}
