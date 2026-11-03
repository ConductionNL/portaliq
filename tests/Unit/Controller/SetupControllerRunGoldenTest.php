<?php

/**
 * SetupControllerRunGoldenTest
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

use OCA\Portaliq\Controller\SetupController;
use OCA\Portaliq\Service\DemoDataService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The answer, config writes and log lines of every setup action, per stored and posted dataset and import outcome.
 *
 * @covers \OCA\Portaliq\Controller\SetupController
 */
class SetupControllerRunGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/wizard-run-action.json';

	/**
	 * Every scenario's trace matches the recorded one.
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
	 * Run one action over recording doubles.
	 *
	 * @param array<string, mixed> $scenario Action, stored and posted dataset, import outcome.
	 *
	 * @return array<string, mixed>
	 */
	private function trace(array $scenario): array {
		$log = [];

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'dataset' ? ($scenario['posted'] ?? null) : $default)
		);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (string)($scenario['stored'][$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$log): bool {
				$log[] = ['config', $app, $key, $value];
				return true;
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(static function (string $message, array $context = []) use (&$log): void {
			$log[] = ['log.error', $message, ($context['app'] ?? null), isset($context['exception'])];
		});

		$demo = $this->createMock(originalClassName: DemoDataService::class);
		$demo->method('listChoices')->willReturn([['id' => 'demo'], ['id' => 'none'], ['id' => 'zuiddrecht']]);
		$demo->method('install')->willReturnCallback(static function () use (&$log, $scenario): array {
			$log[] = ['install'];
			if (($scenario['import'] ?? null) === 'throw') {
				throw new RuntimeException('register missing');
			}

			return ($scenario['import'] ?? ['objects' => 4]);
		});

		$response = (new SetupController($request, $config, $logger, $demo))->runAction(actionId: (string)$scenario['action']);

		return ['status' => $response->getStatus(), 'data' => $response->getData(), 'log' => $log];
	}//end trace()

	/**
	 * The scenarios by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$picked = ['demo_dataset' => 'zuiddrecht'];
		$load   = ['action' => 'load-demo-data', 'stored' => $picked];

		return [
			'unknown action'              => ['action' => 'nope'],
			'skip'                        => ['action' => 'skip-demo-data', 'stored' => $picked],
			'load without a choice'       => ['action' => 'load-demo-data'],
			'load stored none'            => ['action' => 'load-demo-data', 'stored' => ['demo_dataset' => 'none']],
			'load posted none'            => ['action' => 'load-demo-data', 'posted' => 'none'],
			'load posted unknown'         => ['action' => 'load-demo-data', 'posted' => 'bogus'],
			'load posted list'            => ['action' => 'load-demo-data', 'posted' => ['demo']],
			'load posted int'             => ['action' => 'load-demo-data', 'posted' => 7],
			'load posted overrides store' => ['action' => 'load-demo-data', 'stored' => ['demo_dataset' => 'none'], 'posted' => 'zuiddrecht'],
			'legacy outranks none'        => ['action' => 'install-demo-data', 'stored' => ['demo_dataset' => 'none']],
			'legacy outranks posted'      => ['action' => 'install-demo-data', 'posted' => 'zuiddrecht'],
			'legacy refuses bad post'     => ['action' => 'install-demo-data', 'posted' => 'bogus'],
			'import throws'               => $load + ['import' => 'throw'],
			'import all'                  => $load + ['import' => ['objects' => 4, 'declared' => 4]],
			'import no declared'          => $load + ['import' => ['objects' => 4]],
			'import partial skipped'      => $load + ['import' => ['objects' => 3, 'declared' => 5, 'skipped' => 2]],
			'import rerun present'        => $load + ['import' => ['objects' => 0, 'declared' => 5, 'present' => 5]],
			'import zero none present'    => $load + ['import' => ['objects' => 0, 'declared' => 5, 'skipped' => 5]],
			'import zero nothing'         => $load + ['import' => ['objects' => 0]],
			'import present and skipped'  => $load + ['import' => ['objects' => 0, 'present' => 2, 'skipped' => 1]],
			'import some with present'    => $load + ['import' => ['objects' => 2, 'present' => 2, 'skipped' => 1]],
			'import string counts'        => $load + ['import' => ['objects' => '2', 'declared' => '3', 'skipped' => '1']],
			'import negative skipped'     => $load + ['import' => ['objects' => 2, 'skipped' => -1]],
		];
	}//end scenarios()
}//end class
