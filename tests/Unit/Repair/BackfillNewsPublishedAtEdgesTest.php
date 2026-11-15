<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\BackfillNewsPublishedAt;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * news-publish-date T3, the edges: without OpenRegister the step does
 * nothing; an item whose creation moment cannot be read and an item whose
 * save fails are logged and not counted; the count names only what was written.
 *
 * @covers \OCA\Portaliq\Repair\BackfillNewsPublishedAt
 * @uses   \OCA\Portaliq\Service\ObjectServiceLookup
 *
 * @spec openspec/changes/archive/2026-10-07-news-publish-date/tasks.md#T3
 */
class BackfillNewsPublishedAtEdgesTest extends TestCase {

	/**
	 * Every save, as [uuid, publishedAt].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $saves = [];

	/**
	 * The step over these rows.
	 *
	 * @param array<int, array<string, mixed>> $rows         The news items.
	 * @param bool                             $openRegister Whether OpenRegister resolves.
	 * @param LoggerInterface|null             $logger       The logger.
	 *
	 * @return BackfillNewsPublishedAt
	 */
	private function step(array $rows, bool $openRegister = true, ?LoggerInterface $logger = null): BackfillNewsPublishedAt {
		$store = new class($this, $rows) {
			/**
			 * @param BackfillNewsPublishedAtEdgesTest $test The test recording saves.
			 * @param array<int, array<string, mixed>> $rows The rows.
			 */
			public function __construct(private BackfillNewsPublishedAtEdgesTest $test, private array $rows) {
			}

			/**
			 * @param array<string, mixed> $config Paging.
			 * @param mixed ...$flags RBAC and multitenancy.
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], mixed ...$flags): array {
				return array_slice($this->rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 100));
			}

			/**
			 * @param mixed ...$args Object, register, schema, uuid and flags.
			 * @return array<string, mixed>
			 */
			public function saveObject(mixed ...$args): array {
				if ($args['uuid'] === 'n-locked') {
					throw new RuntimeException('object is locked');
				}

				$this->test->saves[] = [$args['uuid'], $args['object']['publishedAt']];
				return $args['object'];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function () use ($store, $openRegister): object {
				if ($openRegister === false) {
					throw new RuntimeException('OpenRegister is not installed');
				}

				return $store;
			}
		);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		return new BackfillNewsPublishedAt($container, $context, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end step()

	/**
	 * Without OpenRegister nothing is read or reported.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingHappens(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('info');

		$this->step(rows: [['status' => 'published', '@self' => ['uuid' => 'n1', 'created' => '2026-09-01T08:00:00+00:00']]], openRegister: false)->run($output);

		self::assertSame([], $this->saves);
		self::assertSame('Give published news items their publish moment', $this->step(rows: [])->getName());
	}//end testWithoutOpenRegisterNothingHappens()

	/**
	 * An unreadable creation moment and a refused save are logged and not counted.
	 *
	 * @return void
	 */
	public function testItemsThatCannotBeStampedAreLoggedNotCounted(): void {
		$warned = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warned): void {
				$warned[] = [$message, $context['uuid'] ?? null];
			}
		);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('BackfillNewsPublishedAt: stamped 1 news items.');

		$this->step(
			rows: [
				['status' => 'published', '@self' => ['uuid' => 'n-garbled', 'created' => 'yesterday-ish at noon-ish']],
				['status' => 'published', '@self' => ['uuid' => 'n-locked', 'created' => '2026-09-01T08:00:00+00:00']],
				['status' => 'published', 'id' => 'n-ok', '@self' => ['created' => '2026-09-02T10:30:00+02:00']],
			],
			logger: $logger
		)->run($output);

		self::assertSame([['n-ok', '2026-09-02T08:30:00+00:00']], $this->saves);
		self::assertSame(
			[
				['Portaliq: a news item could not be given its publish moment', 'n-garbled'],
				['Portaliq: a news item could not be given its publish moment', 'n-locked'],
			],
			$warned
		);
	}//end testItemsThatCannotBeStampedAreLoggedNotCounted()
}//end class
