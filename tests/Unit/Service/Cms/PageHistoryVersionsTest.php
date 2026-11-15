<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use DateTimeImmutable;
use OCA\Portaliq\Service\Cms\PageHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A page's published versions read from OpenRegister's audit trail, with
 * plain doubles for the two mappers so it runs without OpenRegister
 * installed (PageHistoryTest pins the same over the real classes when it is).
 */
#[CoversClass(PageHistory::class)]
class PageHistoryVersionsTest extends TestCase {

	private const PAGE = 'page-uuid';

	/**
	 * What the doubles were asked.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $asked = [];

	protected function setUp(): void {
		$this->asked = [];
	}//end setUp()

	/**
	 * An audit row.
	 *
	 * @param int        $id      The row id.
	 * @param mixed      $changed The changed payload.
	 * @param int|string $schema  The schema id.
	 * @param mixed      $created The created moment.
	 * @param ?string    $name    The user's display name.
	 * @param ?string    $user    The user id.
	 *
	 * @return object
	 */
	private static function row(int $id, mixed $changed, int|string $schema = 12, mixed $created = null, ?string $name = 'Anna', ?string $user = 'anna'): object {
		return new class ($id, $changed, $schema, $created, $name, $user) {
			public function __construct(
				private int $id,
				private mixed $changed,
				private int|string $schema,
				private mixed $created,
				private ?string $name,
				private ?string $user,
			) {
			}

			public function getId(): int {
				return $this->id;
			}

			public function getChanged(): mixed {
				return $this->changed;
			}

			public function getSchema(): int|string {
				return $this->schema;
			}

			public function getCreated(): mixed {
				return $this->created;
			}

			public function getUserName(): ?string {
				return $this->name;
			}

			public function getUser(): ?string {
				return $this->user;
			}
		};
	}//end row()

	/**
	 * The history over $rows, with the page schema at id 12 (or none).
	 *
	 * @param list<object>         $rows   The audit rows, newest first.
	 * @param bool                 $schema Whether the page schema exists.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return PageHistory
	 */
	private function history(array $rows, bool $schema = true, ?LoggerInterface $logger = null): PageHistory {
		$asked = &$this->asked;
		$trail = new class ($rows, $asked) {
			public function __construct(private array $rows, private array &$asked) {
			}

			public function findForObjectByAction(string $id, array $actions, int $limit): array {
				$this->asked[] = ['trail' => [$id, $actions, $limit]];
				return $this->rows;
			}
		};
		$schemas = new class ($schema, $asked) {
			public function __construct(private bool $exists, private array &$asked) {
			}

			public function findByApplicationAndSlug(string $slug, string $application): ?object {
				$this->asked[] = ['schema' => [$slug, $application]];
				if ($this->exists === false) {
					return null;
				}
				return new class {
					public function getId(): int {
						return 12;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Db\AuditTrailMapper' => $trail,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
			}
		);

		return new PageHistory($container, $logger ?? $this->createMock(LoggerInterface::class));
	}//end history()

	public function testPublishedBodiesAreRestorableVersionsInTrailOrder(): void {
		$at = new DateTimeImmutable('2026-10-01T10:00:00+00:00');
		$history = $this->history([
			self::row(3, ['body' => ['new' => ['blocks' => [2]]], 'status' => ['new' => 'published']], 12, $at),
			self::row(2, ['body' => ['new' => ['blocks' => [1]]]], '12', null, '', 'piet'),
		]);

		$this->assertSame(
			[
				['id' => 3, 'publishedAt' => '2026-10-01T10:00:00+00:00', 'by' => 'Anna', 'restorable' => true, 'body' => ['blocks' => [2]]],
				['id' => 2, 'publishedAt' => '', 'by' => 'piet', 'restorable' => true, 'body' => ['blocks' => [1]]],
			],
			$history->versions(self::PAGE)
		);
		$this->assertSame(
			[['schema' => ['page', 'portaliq']], ['trail' => [self::PAGE, ['create', 'update'], 50]]],
			$this->asked
		);
	}//end testPublishedBodiesAreRestorableVersionsInTrailOrder()

	public function testAPublicationWithoutABodyIsShownButNotRestorable(): void {
		$versions = $this->history([self::row(4, ['status' => ['new' => 'published'], 'body' => ['new' => 'not blocks']], 12, null, null, null)])->versions(self::PAGE);

		$this->assertSame([['id' => 4, 'publishedAt' => '', 'by' => '', 'restorable' => false, 'body' => null]], $versions);
	}//end testAPublicationWithoutABodyIsShownButNotRestorable()

	public function testDraftSavesUnreadableRowsAndOtherSchemasAreNoVersions(): void {
		$versions = $this->history([
			self::row(5, ['status' => ['new' => 'draft']]),
			self::row(6, 'not an array'),
			self::row(7, ['body' => ['new' => ['x']]], 99),
		])->versions(self::PAGE);

		$this->assertSame([], $versions);
	}//end testDraftSavesUnreadableRowsAndOtherSchemasAreNoVersions()

	public function testWithoutThePageSchemaTheTrailIsNotRead(): void {
		$this->assertSame([], $this->history([self::row(1, ['body' => ['new' => []]])], false)->versions(self::PAGE));
		$this->assertSame([['schema' => ['page', 'portaliq']]], $this->asked);
	}//end testWithoutThePageSchemaTheTrailIsNotRead()

	public function testAnUnreadableTrailIsLoggedAndHasNoHistory(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Portaliq: page history unavailable', ['reason' => 'no openregister']);

		$this->assertSame([], (new PageHistory($container, $logger))->versions(self::PAGE));
	}//end testAnUnreadableTrailIsLoggedAndHasNoHistory()
}//end class
