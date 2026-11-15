<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Articles;

use JsonSerializable;
use OCA\Portaliq\Service\Articles\ArticleStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * marketing-articles-from-pipelinq: the store reads and writes `article`
 * in the `portaliq` register through OpenRegister, unscoped, and degrades to
 * nothing when OpenRegister is absent or fails.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */
class ArticleStoreTest extends TestCase {

	/**
	 * The calls the object service double received.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * A store over an object service double.
	 *
	 * @param object|null $service The object service, or null when OpenRegister is absent.
	 *
	 * @return ArticleStore
	 */
	private function store(?object $service): ArticleStore {
		$container = $this->createMock(ContainerInterface::class);
		if ($service === null) {
			$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		} else {
			$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($service);
		}

		return new ArticleStore(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end store()

	/**
	 * An object service double that records its calls.
	 *
	 * @param mixed $answer What every call answers, or a Throwable to throw.
	 *
	 * @return object
	 */
	private function service(mixed $answer): object {
		$calls = &$this->calls;
		return new class ($answer, $calls) {
			/**
			 * @param mixed $answer The answer.
			 * @param array<int, array{0: string, 1: array<string, mixed>}> $calls The calls.
			 */
			public function __construct(private mixed $answer, private array &$calls) {
			}

			/**
			 * @param mixed ...$args The call.
			 *
			 * @return mixed
			 */
			public function find(mixed ...$args): mixed {
				return $this->answer('find', $args);
			}

			/**
			 * @param mixed ...$args The call.
			 *
			 * @return mixed
			 */
			public function findAll(mixed ...$args): mixed {
				return $this->answer('findAll', $args);
			}

			/**
			 * @param mixed ...$args The call.
			 *
			 * @return mixed
			 */
			public function saveObject(mixed ...$args): mixed {
				return $this->answer('saveObject', $args);
			}

			/**
			 * @param string $method The method.
			 * @param array<string, mixed> $args The named arguments.
			 *
			 * @return mixed
			 */
			private function answer(string $method, array $args): mixed {
				$this->calls[] = [$method, $args];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end service()

	/**
	 * An entity that serialises to a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return JsonSerializable
	 */
	private function entity(array $row): JsonSerializable {
		return new class ($row) implements JsonSerializable {
			/**
			 * @param array<string, mixed> $row The row.
			 */
			public function __construct(private array $row) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->row;
			}
		};
	}//end entity()

	public function testFindReadsOneArticleUnscoped(): void {
		$row = $this->store($this->service($this->entity(['uuid' => 'a-1', 'title' => 'Een'])))->find('a-1');

		$this->assertSame(['uuid' => 'a-1', 'title' => 'Een'], $row);
		$this->assertSame(['find', ['id' => 'a-1', 'register' => 'portaliq', 'schema' => 'article', '_rbac' => false, '_multitenancy' => false]], $this->calls[0]);
	}//end testFindReadsOneArticleUnscoped()

	public function testFindAllReadsEveryArticleOfTheSchema(): void {
		$rows = $this->store($this->service([$this->entity(['uuid' => 'a-1']), ['uuid' => 'a-2'], $this->entity([]), 'junk']))->findAll();

		$this->assertSame([['uuid' => 'a-1'], ['uuid' => 'a-2']], $rows);
		$this->assertSame(['config' => ['filters' => ['register' => 'portaliq', 'schema' => 'article']], '_rbac' => false, '_multitenancy' => false], $this->calls[0][1]);
	}//end testFindAllReadsEveryArticleOfTheSchema()

	public function testSaveCreatesWithoutAnIdAndUpdatesWithOne(): void {
		$store = $this->store($this->service(['uuid' => 'a-1', 'title' => 'Een']));

		$this->assertSame(['uuid' => 'a-1', 'title' => 'Een'], $store->save(['title' => 'Een']));
		$store->save(['title' => 'Twee'], 'a-1');

		$this->assertNull($this->calls[0][1]['uuid']);
		$this->assertSame('a-1', $this->calls[1][1]['uuid']);
		$this->assertSame(['title' => 'Twee'], $this->calls[1][1]['object']);
		$this->assertSame(['portaliq', 'article', false, false], [$this->calls[1][1]['register'], $this->calls[1][1]['schema'], $this->calls[1][1]['_rbac'], $this->calls[1][1]['_multitenancy']]);
	}//end testSaveCreatesWithoutAnIdAndUpdatesWithOne()

	public function testAFailureOrNoOpenRegisterAnswersNothing(): void {
		$failing = $this->store($this->service(new RuntimeException('down')));
		$this->assertNull($failing->find('a-1'));
		$this->assertSame([], $failing->findAll());
		$this->assertNull($failing->save(['title' => 'Een']));

		$absent = $this->store(null);
		$this->assertNull($absent->find('a-1'));
		$this->assertSame([], $absent->findAll());
		$this->assertNull($absent->save(['title' => 'Een']));
		$this->assertNull($this->store($this->service(null))->find('a-1'));
		$this->assertNull($this->store($this->service($this->entity([])))->find(''));
	}//end testAFailureOrNoOpenRegisterAnswersNothing()

	public function testTheIdComesFromTheRowOrItsSelf(): void {
		$this->assertSame('u-1', ArticleStore::idOf(['uuid' => 'u-1', 'id' => 7]));
		$this->assertSame('7', ArticleStore::idOf(['id' => 7]));
		$this->assertSame('s-1', ArticleStore::idOf(['@self' => ['id' => 's-1']]));
		$this->assertSame('', ArticleStore::idOf(['slug' => 'een']));
	}//end testTheIdComesFromTheRowOrItsSelf()
}//end class
