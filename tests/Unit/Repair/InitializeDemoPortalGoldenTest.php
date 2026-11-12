<?php

/**
 * InitializeDemoPortalGoldenTest
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

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\InitializeDemoPortal;
use OCA\Portaliq\Service\PortalObjectCounter;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every step the demo-portal repair takes, in order, per guard and per failed write.
 *
 * @covers \OCA\Portaliq\Repair\InitializeDemoPortal
 */
class InitializeDemoPortalGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/initialize-demo-portal.json';

	/**
	 * The ordered trace of every scenario matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryScenarioMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->trace(scenario: $scenario);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryScenarioMatchesTheGolden()

	/**
	 * Run the step over recording doubles and return what it did, in order.
	 *
	 * @param array<string, mixed> $scenario Config values, portal count and per-title write results.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function trace(array $scenario): array {
		$log = [];

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (string)($scenario['config'][$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$log): bool {
				$log[] = ['config', $app, $key, $value];
				return true;
			}
		);

		$writer = $this->createMock(originalClassName: PortalObjectWriter::class);
		$counter = $this->createMock(originalClassName: PortalObjectCounter::class);
		$counter->method('countObjects')->willReturnCallback(
			static function (string $register, string $schema) use (&$log, $scenario): int {
				// The recording names the filters too; a count runs without any.
				$log[] = ['count', $register, $schema, []];
				return (int)($scenario['existing'] ?? 0);
			}
		);
		$writer->method('createAnonymousObject')->willReturnCallback(
			static function (string $register, string $schema, array $data) use (&$log, $scenario): ?array {
				$log[] = ['write', $register, $schema, $data];
				$results = ($scenario['results'] ?? []);
				if (array_key_exists($data['title'], $results) === true) {
					return $results[$data['title']];
				}

				return ['@self' => ['id' => 'id-' . $data['title']]];
			}
		);

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$log): void {
			$log[] = ['info', $message];
		});
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$log): void {
			$log[] = ['warning', $message];
		});

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message) use (&$log): void {
			$log[] = ['log.warning', $message];
		});

		(new InitializeDemoPortal(writer: $writer, appConfig: $config, logger: $logger, counter: $counter))->run(output: $output);

		return $log;
	}//end trace()

	/**
	 * The scenarios by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		return [
			'switched off'                => ['config' => ['demo_portal' => 'no']],
			'switched off and marked'     => ['config' => ['demo_portal' => 'no', 'demo_portal_provisioned' => 'p-1']],
			'other switch value'          => ['config' => ['demo_portal' => 'No']],
			'already marked'              => ['config' => ['demo_portal_provisioned' => 'p-1']],
			'one portal present'          => ['existing' => 1],
			'three portals present'       => ['existing' => 3],
			'negative count'              => ['existing' => -1],
			'portal write fails'          => ['results' => ['Open Catalogi' => null]],
			'portal without id'           => ['results' => ['Open Catalogi' => ['title' => 'x']]],
			'portal with flat id'         => ['results' => ['Open Catalogi' => ['id' => 'flat-1']]],
			'portal self id null'         => ['results' => ['Open Catalogi' => ['@self' => ['id' => null], 'id' => 'flat-2']]],
			'portal self id zero'         => ['results' => ['Open Catalogi' => ['@self' => ['id' => 0]]]],
			'home page fails'             => ['results' => ['Home' => null]],
			'detail page and menu fail'   => ['results' => ['Publicatie' => null, 'Sub footer menu 1' => null]],
			'search page fails'           => ['results' => ['Zoeken' => null]],
			'search page empty array'     => ['results' => ['Zoeken' => []]],
			'everything lands'            => [],
			'explicit yes'                => ['config' => ['demo_portal' => 'yes', 'demo_portal_provisioned' => '']],
		];
	}//end scenarios()
}//end class
