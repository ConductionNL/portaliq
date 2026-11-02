<?php

/**
 * Portaliq Public Assistant Channel
 *
 * The channel adapter that passes an anonymous question to hermiq and the
 * answer back, with no identity and no tools.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Assistant
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
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Assistant;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Duck-typed client for hermiq's entry point for channel adapters.
 *
 * It is in-process and carries no session, subject, organisation claim,
 * address or visitor id. When hermiq or its entry point is missing the
 * channel is unavailable and the widget stays off.
 *
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
 */
class PublicAssistantChannel {

	/**
	 * The entry point hermiq publishes for channel adapters, by name only.
	 * Nextcloud autoloads an app's classes while the app is enabled, so
	 * `class_exists()` doubles as the "is hermiq on" check.
	 *
	 * @var string
	 */
	public const ENTRY_CLASS = 'OCA\\Hermiq\\Service\\PublicChannelConversation';

	/**
	 * The longest question forwarded.
	 *
	 * @var int
	 */
	public const MAX_QUESTION = 500;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves hermiq's entry point when it is there.
	 * @param LoggerInterface $logger The logger.
	 * @param PublicSourceScope $scope What the assistant may read.
	 * @param PersonalDetailsFilter $filter Removes personal details from a question.
	 * @param string $entryClass The entry point's class name; a test names a fake.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PublicSourceScope $scope,
		private readonly PersonalDetailsFilter $filter,
		private readonly string $entryClass = self::ENTRY_CLASS,
	) {
	}//end __construct()

	/**
	 * Whether hermiq's entry point can be called.
	 *
	 * @return bool True when it is installed and resolves.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
	 */
	public function isAvailable(): bool {
		return $this->entry() !== null;
	}//end isAvailable()

	/**
	 * Ask one question.
	 *
	 * @param array<string, mixed> $portal The resolved portal object.
	 * @param string $question The visitor's question.
	 * @param string $locale The visitor's locale.
	 * @param string $conversationId A conversation id hermiq issued earlier in the visit, or ''.
	 *
	 * @return array{status: string, answer: ?string, sources: array<int, array<string, string>>, removed: bool, conversationId: string}
	 *         `status` is `answered`, `abstained` or `unavailable`. An answer with no
	 *         admitted source is an abstention and carries no text.
	 *
	 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t03
	 */
	public function ask(array $portal, string $question, string $locale, string $conversationId=''): array {
		$empty = ['status' => 'unavailable', 'answer' => null, 'sources' => [], 'removed' => false, 'conversationId' => ''];
		$entry = $this->entry();
		if ($entry === null) {
			return $empty;
		}

		$clean = $this->filter->strip(text: mb_substr(trim($question), 0, self::MAX_QUESTION));
		$scope = $this->scope->forPortal(portal: (string)($portal['slug'] ?? ''), settings: $this->scope->settingsFor(portal: $portal));

		try {
			// Only what is named here is forwarded: no session, subject, claim, address or visitor id.
			$reply = $entry->converse(
				question: $clean['text'],
				portal: $scope['portal'],
				locale: $locale,
				scope: $scope,
				conversationId: $conversationId,
				tools: [],
			);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: public assistant unavailable', ['reason' => $e->getMessage()]);
			return $empty;
		}

		$sources = $this->sourcesFrom(reply: $reply, scope: $scope);
		$answer  = $this->text(reply: $reply, key: 'answer');
		$issued  = $this->text(reply: $reply, key: 'conversationId');

		// No source supports it: abstain, and show no generated text.
		if ($sources === [] || $answer === '') {
			return ['status' => 'abstained', 'answer' => null, 'sources' => [], 'removed' => $clean['removed'], 'conversationId' => $issued];
		}

		return ['status' => 'answered', 'answer' => $answer, 'sources' => $sources, 'removed' => $clean['removed'], 'conversationId' => $issued];
	}//end ask()

	/**
	 * The sources of a reply that sit inside the scope and can be linked.
	 *
	 * @param mixed $reply hermiq's reply.
	 * @param array<string, mixed> $scope The scope.
	 *
	 * @return array<int, array<string, string>> The admitted sources.
	 */
	private function sourcesFrom(mixed $reply, array $scope): array {
		if (is_array($reply) === false) {
			return [];
		}

		$sources = [];
		foreach ((array)($reply['sources'] ?? []) as $source) {
			if (is_array($source) === false || $this->scope->admits(source: $source, scope: $scope) === false) {
				continue;
			}

			$url   = (string)($source['url'] ?? '');
			$title = (string)($source['title'] ?? '');
			if ($title === '' || preg_match('#^(https?://|/)#', $url) !== 1) {
				continue;
			}

			$sources[] = ['title' => $title, 'url' => $url, 'type' => (string)$source['schema']];
		}

		return $sources;
	}//end sourcesFrom()

	/**
	 * Hermiq's entry point, or null when it is not enabled or cannot be built.
	 *
	 * @return object|null The entry point.
	 */
	private function entry(): ?object {
		if (class_exists($this->entryClass) === false) {
			return null;
		}

		try {
			$entry = $this->container->get($this->entryClass);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: public assistant entry point cannot be built', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_object($entry) === true && method_exists($entry, 'converse') === true) {
			return $entry;
		}

		return null;
	}//end entry()

	/**
	 * One trimmed string out of a reply, or ''.
	 *
	 * @param mixed $reply hermiq's reply.
	 * @param string $key The key to read.
	 *
	 * @return string The text.
	 */
	private function text(mixed $reply, string $key): string {
		if (is_array($reply) === true && is_string($reply[$key] ?? null) === true) {
			return trim($reply[$key]);
		}

		return '';
	}//end text()
}//end class
