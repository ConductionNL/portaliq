<?php

/**
 * SubmitFailureLog unit tests
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
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

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\SubmitFailureLog;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A failed submit's error code, evidence line and digest entry (REQ-FGV-003).
 *
 * @covers \OCA\Portaliq\Service\Intake\SubmitFailureLog
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */
class SubmitFailureLogTest extends TestCase {

	/**
	 * The stored app config value.
	 *
	 * @var string
	 */
	private string $stored = '[]';

	/**
	 * The clock's answer.
	 *
	 * @var integer
	 */
	private int $now = 1_800_000_000;

	/**
	 * A log over an in-memory app config, a clock and a logger.
	 *
	 * @param LoggerInterface|null $logger The logger, or a silent one.
	 * @param boolean              $broken Whether writing the config fails.
	 *
	 * @return SubmitFailureLog The log.
	 */
	private function log(?LoggerInterface $logger = null, bool $broken = false): SubmitFailureLog {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use ($broken): bool {
				if ($broken === true) {
					throw new RuntimeException('config down');
				}

				$this->assertSame(expected: 'portaliq', actual: $app);
				$this->assertSame(expected: SubmitFailureLog::CONFIG_KEY, actual: $key);
				$this->stored = $value;
				return true;
			}
		);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new SubmitFailureLog(appConfig: $config, logger: $logger ?? $this->createMock(originalClassName: LoggerInterface::class), time: $time);
	}//end log()

	/**
	 * A failure gets a code, an evidence line naming the code and the form, and a digest entry.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedUnderItsCodeAndKeptForTheDigest(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->matchesRegularExpression(pattern: '/form submit failed OF-[0-9A-F]{4}: zuid\/woo answered 503: down/'),
			$this->callback(callback: static fn (array $context): bool => $context['route'] === 'woo' && $context['status'] === 503)
		);

		$binding = ['portal' => 'zuid', 'route' => 'woo', 'title' => 'Woo-verzoek'];
		$code    = $this->log(logger: $logger)->record(binding: $binding, status: 503, message: 'down');

		$this->assertMatchesRegularExpression(pattern: '/^OF-[0-9A-F]{4}$/', string: $code);
		$this->assertSame(
			expected: [['code' => $code, 'portal' => 'zuid', 'route' => 'woo', 'title' => 'Woo-verzoek', 'status' => 503, 'at' => $this->now]],
			actual: $this->log()->since(from: $this->now - 60)
		);
	}//end testAFailureIsLoggedUnderItsCodeAndKeptForTheDigest()

	/**
	 * Since reads only the window asked for; entries older than two days are dropped on the next record.
	 *
	 * @return void
	 */
	public function testOldEntriesFallOutAndSinceReadsItsWindow(): void {
		$log = $this->log();
		$this->now = 1_800_000_000;
		$old = $log->record(binding: ['route' => 'a'], status: 503, message: '');
		$this->now += 86_400;
		$day = $log->record(binding: ['route' => 'b'], status: 500, message: '');

		$this->assertSame(expected: [$day], actual: array_column($log->since(from: $this->now - 3_600), 'code'));
		$this->assertSame(expected: [$old, $day], actual: array_column($log->since(from: 0), 'code'));
		// The title falls back to the route.
		$this->assertSame(expected: 'b', actual: $log->since(from: $this->now)[0]['title']);

		$this->now += SubmitFailureLog::KEEP_SECONDS;
		$last = $log->record(binding: ['route' => 'c'], status: 503, message: '');
		$this->assertSame(expected: [$last], actual: array_column($log->since(from: 0), 'code'));
	}//end testOldEntriesFallOutAndSinceReadsItsWindow()

	/**
	 * The list never grows past MAX_ENTRIES; the oldest go first.
	 *
	 * @return void
	 */
	public function testTheListIsCapped(): void {
		$entries = [];
		for ($i = 0; $i < SubmitFailureLog::MAX_ENTRIES; $i++) {
			$entries[] = ['code' => 'OF-' . $i, 'at' => $this->now];
		}

		$this->stored = (string)json_encode($entries);
		$code         = $this->log()->record(binding: [], status: 503, message: '');
		$kept         = array_column($this->log()->since(from: 0), 'code');

		$this->assertCount(expectedCount: SubmitFailureLog::MAX_ENTRIES, haystack: $kept);
		$this->assertSame(expected: 'OF-1', actual: $kept[0]);
		$this->assertSame(expected: $code, actual: $kept[SubmitFailureLog::MAX_ENTRIES - 1]);
	}//end testTheListIsCapped()

	/**
	 * A broken stored value reads as no failures, and entries without a code are skipped.
	 *
	 * @return void
	 */
	public function testABrokenValueReadsAsNone(): void {
		$this->stored = 'not json';
		$this->assertSame(expected: [], actual: $this->log()->since(from: 0));

		$this->stored = (string)json_encode([['portal' => 'x'], 'text', ['code' => 'OF-AAAA', 'at' => $this->now]]);
		$this->assertSame(expected: ['OF-AAAA'], actual: array_column($this->log()->since(from: 0), 'code'));
	}//end testABrokenValueReadsAsNone()

	/**
	 * When the config cannot be written the code still comes back.
	 *
	 * @return void
	 */
	public function testACodeComesBackWhenTheDigestCannotKeepIt(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->stringContains(string: 'config down'));

		$this->assertStringStartsWith(prefix: 'OF-', string: $this->log(logger: $logger, broken: true)->record(binding: [], status: 503, message: ''));
	}//end testACodeComesBackWhenTheDigestCannotKeepIt()

	/**
	 * Two codes in a row differ, and both have the shape the resident reads.
	 *
	 * @return void
	 */
	public function testCodesHaveTheirShape(): void {
		$codes = array_map(static fn (): string => SubmitFailureLog::newCode(), range(1, 20));

		foreach ($codes as $code) {
			$this->assertMatchesRegularExpression(pattern: '/^OF-[0-9A-F]{4}$/', string: $code);
		}

		$this->assertGreaterThan(expected: 1, actual: count(array_unique($codes)));
	}//end testCodesHaveTheirShape()
}//end class
