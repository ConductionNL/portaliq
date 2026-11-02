<?php

/**
 * PublishedPortalsGoldenTest
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

use JsonSerializable;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PublishedPortals;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden record of the published-portal read: every container lookup, the
 * register-context call, the ObjectService calls with their arguments by
 * name, every log line and every result, per scenario.
 *
 * Set PORTALIQ_GOLDEN_RECORD=1 to rewrite the golden file.
 *
 * @covers \OCA\Portaliq\Service\PortalResolver
 * @covers \OCA\Portaliq\Service\PublishedPortals
 */
class PublishedPortalsGoldenTest extends TestCase {

	/**
	 * The golden file.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__ . '/Golden/published-portals.json';

	/**
	 * Interactions of the running scenario, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $events = [];


	/**
	 * Every scenario, through every way the read is reached, matches the golden record.
	 *
	 * @return void
	 */
	public function testEveryScenarioMatchesTheGoldenRecord(): void {
		$recorded = [];
		foreach ($this->scenarios() as $name => $scenario) {
			foreach ($this->readers() as $via => $build) {
				$this->events = [];
				$reader       = $build($this->container(scenario: $scenario), $this->logger(), $this->context(scenario: $scenario));
				for ($call = 0; $call < $scenario['calls']; $call++) {
					$this->events[] = ['result' => $reader->allPublishedPortals()];
				}

				$recorded[$via][$name] = $this->events;
			}
		}

		$first = reset($recorded);
		if (getenv('PORTALIQ_GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($recorded as $via => $events) {
			$this->assertSame($golden, json_decode((string)json_encode($events), true), 'read via ' . $via);
		}
	}//end testEveryScenarioMatchesTheGoldenRecord()


	/**
	 * Builders of the objects that answer `allPublishedPortals()`, keyed by name.
	 *
	 * @return array<string, callable(ContainerInterface, LoggerInterface, PortalRegisterContext): object>
	 */
	private function readers(): array {
		return [
			'resolver' => static fn (ContainerInterface $container, LoggerInterface $logger, PortalRegisterContext $context): object
				=> new PortalResolver(publishedPortals: new PublishedPortals(container: $container, logger: $logger, context: $context), logger: $logger),
			'publishedPortals' => static fn (ContainerInterface $container, LoggerInterface $logger, PortalRegisterContext $context): object
				=> new PublishedPortals(container: $container, logger: $logger, context: $context),
		];
	}//end readers()


	/**
	 * The scenarios, keyed by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$rowA = ['slug' => 'open-tilburg', 'status' => 'published', 'domains' => [['hostname' => 'tilburg.example', 'verified' => true]]];
		$rowB = ['slug' => 'open-venray', 'organisation' => 'Venray', 'locales' => ['nl']];

		return [
			'array rows, read three times' => ['rows' => [$rowA, $rowB], 'calls' => 3],
			'serialisable rows' => ['rows' => [$this->entity(data: $rowA), $this->entity(data: $rowB)], 'calls' => 2],
			'mixed rows' => ['rows' => [$rowA, $this->entity(data: $rowB)], 'calls' => 1],
			'no rows' => ['rows' => [], 'calls' => 2],
			'context not applied' => ['rows' => [$rowA], 'apply' => false, 'calls' => 2],
			'container throws' => ['rows' => [$rowA], 'containerThrows' => true, 'calls' => 2],
			'read throws' => ['rows' => [$rowA], 'readThrows' => true, 'calls' => 2],
			'read answers a non-array' => ['rows' => 'not a list', 'calls' => 2],
		];
	}//end scenarios()


	/**
	 * A JsonSerializable row standing in for an OpenRegister entity.
	 *
	 * @param array<string, mixed> $data The serialised form.
	 *
	 * @return JsonSerializable The entity double.
	 */
	private function entity(array $data): JsonSerializable {
		return new class($data) implements JsonSerializable {


			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data The serialised form.
			 */
			public function __construct(private readonly array $data) {
			}


			/**
			 * The serialised form.
			 *
			 * @return array<string, mixed> The data.
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}


		};
	}//end entity()


	/**
	 * A container that records its lookups and hands out a recording ObjectService.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return ContainerInterface The container double.
	 */
	private function container(array $scenario): ContainerInterface {
		$events        = &$this->events;
		$objectService = new class($scenario, $events) {


			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>       $scenario The scenario.
			 * @param list<array<string, mixed>> $events   The shared event log.
			 */
			public function __construct(private readonly array $scenario, private array &$events) {
			}


			/**
			 * Record a call with its arguments as passed (named arguments keep their names).
			 *
			 * @param string $method The method.
			 * @param array  $args   The arguments.
			 *
			 * @return mixed The scenario's rows for findAll.
			 */
			public function __call(string $method, array $args): mixed {
				$this->events[] = ['objectService' => $method, 'args' => $args];
				if (($this->scenario['readThrows'] ?? false) === true) {
					throw new RuntimeException('read failed');
				}

				return $this->scenario['rows'];
			}


		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($scenario, $objectService): object {
				$this->events[] = ['container' => $id];
				if (($scenario['containerThrows'] ?? false) === true) {
					throw new RuntimeException('container failed');
				}

				return $objectService;
			}
		);

		return $container;
	}//end container()


	/**
	 * A register context that records its calls and answers per scenario.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return PortalRegisterContext The context double.
	 */
	private function context(array $scenario): PortalRegisterContext {
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(
			function (object $objectService, string $schemaSlug) use ($scenario): bool {
				$this->events[] = ['contextApply' => $schemaSlug, 'objectService' => get_class($objectService) !== ''];

				return ($scenario['apply'] ?? true);
			}
		);

		return $context;
	}//end context()


	/**
	 * A logger that records every line with its level and context.
	 *
	 * @return LoggerInterface The logger double.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->events[] = ['log' => $level, 'message' => (string)$message, 'context' => $context];
				}
			);
		}

		return $logger;
	}//end logger()
}//end class
