<?php

/**
 * RepairPageGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Repair
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

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\BackfillNewsPublishedAt;
use OCA\Portaliq\Repair\MoveActionSummarySentence;
use OCA\Portaliq\Repair\MoveBindingDestination;
use OCA\Portaliq\Service\PortalRegisterContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Each paged repair step reads one page of its schema exactly as recorded:
 * the schema it points the object service at, the page it asks for, what it
 * logs when the read fails, and which rows it keeps.
 *
 * No coverage metadata on purpose: the test calls one private helper on each
 * step, so class-level coverage metadata would claim the whole class.
 */
class RepairPageGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/repair-page.json';

	/**
	 * What happened so far.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * Every step reads its page as recorded, in every situation.
	 *
	 * @return void
	 */
	public function testEveryStepReadsItsPageAsRecorded(): void {
		$actual = [];
		foreach ([MoveBindingDestination::class, MoveActionSummarySentence::class, BackfillNewsPublishedAt::class] as $class) {
			foreach (['contextRefuses', 'findAllThrows', 'mixedRows', 'notAList', 'nothing'] as $situation) {
				$this->log = [];
				$step = new $class($this->createMock(ContainerInterface::class), $this->context(situation: $situation), $this->logger());
				$answer = (new ReflectionMethod($step, 'page'))->invoke($step, $this->objects(situation: $situation), 200);
				$actual[(new \ReflectionClass($class))->getShortName()][$situation] = ['answer' => $answer, 'log' => $this->log];
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testEveryStepReadsItsPageAsRecorded()

	/**
	 * The register context, which refuses in one situation.
	 *
	 * @param string $situation The situation.
	 *
	 * @return PortalRegisterContext
	 */
	private function context(string $situation): PortalRegisterContext {
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(function (object $objectService, string $schemaSlug) use ($situation): bool {
			$this->log[] = ['apply' => $schemaSlug];
			return $situation !== 'contextRefuses';
		});

		return $context;
	}//end context()

	/**
	 * A logger that records every line.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->log[] = [$level => $message, 'context' => $context];
			});
		}

		return $logger;
	}//end logger()

	/**
	 * An object service whose findAll answers as the situation says.
	 *
	 * @param string $situation The situation.
	 *
	 * @return object
	 */
	private function objects(string $situation): object {
		$test = $this;
		return new class ($test, $situation) {
			public function __construct(private RepairPageGoldenTest $test, private string $situation) {
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->test->note(['findAll' => compact('config', '_rbac', '_multitenancy')]);
				return match ($this->situation) {
					'findAllThrows' => throw new RuntimeException('schema not found'),
					'notAList' => 'one row',
					'nothing' => null,
					default => [
						['id' => 'a'],
						RepairPageGoldenTest::entity(data: ['id' => 'b']),
						RepairPageGoldenTest::entity(data: 'scalar'),
						new \stdClass(),
						7,
					],
				};
			}
		};
	}//end objects()

	/**
	 * An entity whose jsonSerialize answers `$data`.
	 *
	 * @param mixed $data The serialised value.
	 *
	 * @return object
	 */
	public static function entity(mixed $data): object {
		return new class ($data) {
			public function __construct(private mixed $data) {
			}

			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end entity()

	/**
	 * Record one event.
	 *
	 * @param mixed $event The event.
	 *
	 * @return void
	 */
	public function note(mixed $event): void {
		$this->log[] = $event;
	}//end note()
}//end class
