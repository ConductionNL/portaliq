<?php

/**
 * FeedRowReadsGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\EventFeedReader;
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\NewsRowSource;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The event feed and the news rows read every page of a schema the same way:
 * what each returns, what it asks OpenRegister and what it logs, per store
 * answer; and how each finds a row's id.
 *
 * @covers \OCA\Portaliq\Service\EventFeedReader
 * @covers \OCA\Portaliq\Service\NewsRowSource
 */
class FeedRowReadsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/feed-row-reads.json';

	/**
	 * What the store was asked and what was logged in the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $seen = [];

	public function testEveryFeedReadMatchesTheGolden(): void {
		$actual = [];
		foreach (['event' => EventFeedReader::class, 'news' => NewsRowSource::class] as $kind => $class) {
			foreach ($this->answers() as $answerName => $answer) {
				foreach ([['schoolEvent', []], ['eventRsvp', ['guardianRef' => 'g-1']]] as [$schema, $filters]) {
					$reader = $this->reader(class: $class, answer: $answer);
					$result = (new ReflectionMethod($class, 'findAll'))->invoke($reader, $schema, $filters);
					$actual[$kind . ' | ' . $answerName . ' | ' . $schema] = [
						'count' => count($result),
						'rows' => implode(',', array_map(static fn (array $row): string => (string)json_encode($row), array_slice($result, 0, 4))),
						'last' => json_encode(end($result)),
						'seen' => $this->seen,
					];
				}
			}

			$reader = $this->reader(class: $class, answer: 'none');
			foreach ($this->rows() as $rowName => $row) {
				$actual[$kind . ' | rowId | ' . $rowName] = (new ReflectionMethod($class, 'rowId'))->invoke($reader, $row);
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}

	/**
	 * The store answers, by name: a callable from the page number to a page.
	 *
	 * @return array<string, mixed>
	 */
	private function answers(): array {
		$page = static fn (int $from, int $count): array => array_map(static fn (int $n): array => ['id' => 'r' . $n], range($from, $from + $count - 1));

		return [
			'no object service' => 'none',
			'not an object' => 'scalar',
			'empty' => static fn (int $offset): array => [],
			'mixed rows' => static fn (int $offset): array => [['id' => 'a'], 'junk', null, new \ArrayObject(['id' => 'b']), new class implements \JsonSerializable {
				public function jsonSerialize(): mixed {
					return ['id' => 'c'];
				}
			}, ['uuid' => 'd'],
			],
			'two pages' => static fn (int $offset): array => ($offset === 0) ? $page(0, 500) : $page(500, 3),
			'not a list' => static fn (int $offset): mixed => 'nope',
			'second page not a list' => static fn (int $offset): mixed => ($offset === 0) ? $page(0, 500) : null,
			'throws' => static function (int $offset): array {
				throw new RuntimeException('store down');
			},
		];
	}

	/**
	 * Rows to take an id from, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function rows(): array {
		return [
			'id' => ['id' => 7, 'uuid' => 'u'],
			'uuid' => ['uuid' => 'u'],
			'self id' => ['@self' => ['id' => 's', 'uuid' => 'su']],
			'self uuid' => ['@self' => ['uuid' => 'su']],
			'self not an array' => ['@self' => 'x'],
			'nothing' => [],
			'null id' => ['id' => null, 'uuid' => 'u'],
		];
	}

	/**
	 * A reader whose container answers `$answer` for the object service.
	 *
	 * @param class-string $class  The reader class.
	 * @param mixed        $answer 'none', 'scalar' or a page callable.
	 *
	 * @return object
	 */
	private function reader(string $class, mixed $answer): object {
		$this->seen = [];
		$seen = &$this->seen;
		$service = null;
		if (is_callable($answer) === true) {
			$service = new class($answer, $seen) {
				public function __construct(private $answer, private array &$seen) {
				}

				public function setRegister(string $register): void {
					$this->seen[] = ['setRegister' => $register];
				}

				public function setSchema(string $schema): void {
					$this->seen[] = ['setSchema' => $schema];
				}

				public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): mixed {
					$this->seen[] = ['findAll' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];

					return ($this->answer)($config['offset']);
				}
			};
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($answer, $service): mixed {
				if ($answer === 'none') {
					throw new RuntimeException('no ' . $id);
				}

				return ($answer === 'scalar') ? 'not an object' : $service;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$seen): void {
				$seen[] = ['warning' => $message, 'context' => $context];
			}
		);

		if ($class === EventFeedReader::class) {
			return new EventFeedReader($container, $this->createMock(GuardianAudienceFixtureReader::class), $logger);
		}

		return new NewsRowSource($container, $logger);
	}
}
