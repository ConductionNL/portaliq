<?php

/**
 * Unit tests for ActionHistory.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Actions
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

namespace OCA\Portaliq\Tests\Unit\Service\Actions;

use OCA\Portaliq\Service\Actions\ActionHistory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An action's history reads its audit trail: who made it, every tracked field
 * that changed, files added and removed, newest first.
 *
 * @covers \OCA\Portaliq\Service\Actions\ActionHistory
 */
class ActionHistoryTest extends TestCase {

	/**
	 * The history over an audit trail mapper double answering $rows, or $failure.
	 *
	 * @param mixed $rows The rows findChangesForObject answers.
	 * @param \Throwable|null $failure What the container throws instead.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return array{0: ActionHistory, 1: object} The history and the mapper double.
	 */
	private function history(mixed $rows, ?\Throwable $failure = null, ?LoggerInterface $logger = null): array {
		$mapper = new class ($rows) {
			/**
			 * @var array<int, array{0: string, 1: int}>
			 */
			public array $asked = [];

			public function __construct(private mixed $rows) {
			}

			public function findChangesForObject(string $uuid, int $limit): mixed {
				$this->asked[] = [$uuid, $limit];
				return $this->rows;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		if ($failure !== null) {
			$container->method('get')->willThrowException($failure);
		} else {
			$container->method('get')->with('OCA\\OpenRegister\\Db\\AuditTrailMapper')->willReturn($mapper);
		}

		return [new ActionHistory($container, $logger ?? $this->createMock(LoggerInterface::class)), $mapper];
	}//end history()

	/**
	 * A person's name by reference, for the lines.
	 *
	 * @param string $ref The reference.
	 *
	 * @return string The name.
	 */
	private static function nameOf(string $ref): string {
		return match ($ref) {
			'sub-anna' => 'Anna',
			'sub-bert' => 'Bert',
			default => $ref,
		};
	}//end nameOf()

	public function testTheTrailBecomesLinesNewestFirst(): void {
		[$history, $mapper] = $this->history(
			[
				['created' => '2026-10-01T09:00:00+00:00', 'changed' => ['editedBy' => ['new' => 'sub-anna'], 'endDate' => ['new' => '2026-10-31T00:00:00+00:00']]],
				['created' => '2026-10-02T09:00:00+00:00', 'changed' => [
					'editedBy' => ['old' => 'sub-anna', 'new' => 'sub-bert'],
					'status' => ['old' => 'open', 'new' => 'done'],
					'assignee' => ['old' => 'sub-anna', 'new' => 'sub-bert'],
					'notTracked' => ['old' => 'a', 'new' => 'b'],
				]],
				['created' => '2026-10-03T09:00:00+00:00', 'changed' => ['fileName' => ['old' => '', 'new' => 'plan.pdf']]],
				['created' => '2026-10-04T09:00:00+00:00', 'changed' => ['fileName' => ['old' => 'plan.pdf', 'new' => '']]],
				'not a row',
			]
		);

		$lines = $history->lines('action-1', self::nameOf(...));

		$this->assertSame(
			[
				['at' => '2026-10-04T09:00:00+00:00', 'who' => 'Bert', 'kind' => 'fileRemoved', 'field' => 'file', 'from' => 'plan.pdf', 'to' => ''],
				['at' => '2026-10-03T09:00:00+00:00', 'who' => 'Bert', 'kind' => 'file', 'field' => 'file', 'from' => '', 'to' => 'plan.pdf'],
				['at' => '2026-10-02T09:00:00+00:00', 'who' => 'Bert', 'kind' => 'field', 'field' => 'assignee', 'from' => 'Anna', 'to' => 'Bert'],
				['at' => '2026-10-02T09:00:00+00:00', 'who' => 'Bert', 'kind' => 'status', 'field' => 'status', 'from' => 'open', 'to' => 'done'],
				['at' => '2026-10-01T09:00:00+00:00', 'who' => 'Anna', 'kind' => 'created', 'field' => '', 'from' => '', 'to' => '2026-10-31'],
			],
			$lines
		);
		$this->assertSame([['action-1', 200]], $mapper->asked);
	}//end testTheTrailBecomesLinesNewestFirst()

	public function testANonScalarValueReadsAsEmpty(): void {
		[$history] = $this->history(
			[
				['created' => 'a', 'changed' => []],
				['created' => 'b', 'changed' => ['title' => ['old' => ['x'], 'new' => 'Plan']]],
			]
		);

		$this->assertSame(
			[['at' => 'b', 'who' => '', 'kind' => 'field', 'field' => 'title', 'from' => '', 'to' => 'Plan'], ['at' => 'a', 'who' => '', 'kind' => 'created', 'field' => '', 'from' => '', 'to' => '']],
			$history->lines('action-1', self::nameOf(...))
		);
	}//end testANonScalarValueReadsAsEmpty()

	public function testWithoutAnIdTheTrailIsNotRead(): void {
		[$history, $mapper] = $this->history([['created' => 'a', 'changed' => []]]);

		$this->assertSame([], $history->lines('', self::nameOf(...)));
		$this->assertSame([], $mapper->asked);
	}//end testWithoutAnIdTheTrailIsNotRead()

	public function testAnUnreadableTrailIsLoggedAndReadsAsNoHistory(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Portaliq: action history unavailable', ['reason' => 'no audit trail']);
		[$history] = $this->history([], new RuntimeException('no audit trail'), $logger);

		$this->assertSame([], $history->lines('action-1', self::nameOf(...)));
	}//end testAnUnreadableTrailIsLoggedAndReadsAsNoHistory()

	public function testAMapperWithoutTheReadReadsAsNoHistory(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());

		$this->assertSame([], (new ActionHistory($container, $this->createMock(LoggerInterface::class)))->lines('action-1', self::nameOf(...)));
	}//end testAMapperWithoutTheReadReadsAsNoHistory()
}//end class
