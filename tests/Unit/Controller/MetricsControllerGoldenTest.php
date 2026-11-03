<?php

/**
 * MetricsControllerGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MetricsController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\SettingsService;
use OCA\Portaliq\Service\Traffic\TrafficMetrics;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The exposition text, status and headers of the metrics endpoint, per collaborator answer.
 *
 * @covers \OCA\Portaliq\Controller\MetricsController
 */
class MetricsControllerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/metrics-controller-index.json';

	/**
	 * Every scenario's response matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryScenarioMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->trace(scenario: $scenario);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryScenarioMatchesTheGolden()

	/**
	 * Build the controller over scripted doubles and render the endpoint.
	 *
	 * @param array<string, mixed> $scenario The collaborator answers.
	 *
	 * @return array<string, mixed>
	 */
	private function trace(array $scenario): array {
		$log = [];

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(($scenario['healthy'] ?? true));

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($scenario, &$log): mixed {
			$log[] = ['container', $id];
			$service = ($scenario['objects'] ?? 'rows');
			if ($service === 'throw') {
				throw new RuntimeException('no service');
			}

			if ($service === 'scalar') {
				return 'not an object';
			}

			return new MetricsGoldenObjectService(log: $log, mode: $service);
		});

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(static function (string $message, array $context = []) use (&$log): void {
			$log[] = ['log.error', $message, isset($context['exception'])];
		});
		$logger->method('debug')->willReturnCallback(static function (string $message, array $context = []) use (&$log): void {
			$log[] = ['log.debug', $message, $context];
		});

		$auditor = $this->createMock(originalClassName: AuditTrailService::class);
		if (($scenario['audit'] ?? null) === 'throw') {
			$auditor->method('countsByVerb')->willThrowException(new RuntimeException('audit down'));
		} else {
			$auditor->method('countsByVerb')->willReturn(($scenario['audit'] ?? ['login' => 3, 'logout' => 1]));
		}

		$traffic = $this->createMock(originalClassName: TrafficMetrics::class);
		$traffic->method('acceptedTotal')->willReturn(($scenario['accepted'] ?? 12));
		$traffic->method('refusedByReason')->willReturn(($scenario['refused'] ?? ['origin' => 2, 'rate' => 5]));

		$cms = null;
		if (array_key_exists('cache', $scenario) === false || $scenario['cache'] !== null) {
			$cms = $this->createMock(originalClassName: CmsReader::class);
			$cms->method('cacheStats')->willReturn(($scenario['cache'] ?? ['hits' => 7, 'misses' => 9]));
		}

		$controller = new MetricsController(
			$this->createMock(originalClassName: IRequest::class),
			$settings,
			$container,
			$logger,
			$auditor,
			$traffic,
			$cms
		);
		$response   = $controller->index();

		return [
			'status'  => $response->getStatus(),
			'headers' => array_intersect_key($response->getHeaders(), ['Content-Type' => true]),
			'body'    => explode("\n", $response->render()),
			'log'     => $log,
		];
	}//end trace()

	/**
	 * The scenarios by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		return [
			'all collaborators answer' => [],
			'unhealthy'                => ['healthy' => false],
			'no cms reader'            => ['cache' => null],
			'empty counters'           => ['audit' => [], 'refused' => [], 'accepted' => 0, 'cache' => ['hits' => 0, 'misses' => 0]],
			'object service throws'    => ['objects' => 'throw'],
			'object service scalar'    => ['objects' => 'scalar'],
			'find all throws'          => ['objects' => 'findThrows'],
			'find all not an array'    => ['objects' => 'notArray'],
			'auditor throws'           => ['audit' => 'throw'],
		];
	}//end scenarios()
}//end class

/**
 * An ObjectService double whose findAll answers by schema, or fails as scripted.
 */
class MetricsGoldenObjectService {

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $log  The shared trace, by reference.
	 * @param string            $mode How findAll answers.
	 */
	public function __construct(private array &$log, private readonly string $mode) {
	}//end __construct()

	/**
	 * @param string $register The register.
	 *
	 * @return void
	 */
	public function setRegister(string $register): void {
		$this->log[] = ['setRegister', $register];
	}//end setRegister()

	/**
	 * @param string $schema The schema.
	 *
	 * @return void
	 */
	public function setSchema(string $schema): void {
		$this->log[] = ['setSchema', $schema];
	}//end setSchema()

	/**
	 * @param array<string, mixed> $config        The query.
	 * @param bool                 $_rbac         RBAC flag.
	 * @param bool                 $_multitenancy Multitenancy flag.
	 *
	 * @return mixed
	 */
	public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): mixed {
		$this->log[] = ['findAll', $config, $_rbac, $_multitenancy];
		if ($this->mode === 'findThrows') {
			throw new RuntimeException('find failed');
		}

		if ($this->mode === 'notArray') {
			return 'rows';
		}

		return array_fill(0, (count($this->log) % 4) + 1, ['id' => 'x']);
	}//end findAll()
}//end class
