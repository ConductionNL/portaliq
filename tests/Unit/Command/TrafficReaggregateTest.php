<?php

/**
 * Unit tests for the portaliq:traffic:reaggregate command.
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

namespace OCA\Portaliq\Tests\Unit\Command;

use OCA\Portaliq\Command\TrafficReaggregate;
use OCA\Portaliq\Service\TrafficAggregationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The command runs the service's back-fill, for one portal on request,
 * and reports its counts.
 */
class TrafficReaggregateTest extends TestCase {

	/**
	 * Every portal by default, one with --portal.
	 *
	 * @return void
	 */
	public function testItBackfillsEveryPortalOrTheOneAskedFor(): void {
		$asked = [];
		$service = $this->createMock(TrafficAggregationService::class);
		$service->method('backfill')->willReturnCallback(
			static function (?string $only = null) use (&$asked): array {
				$asked[] = $only;

				return ['portals' => 2, 'days' => 5, 'kept' => 177, 'rollupDays' => 3];
			}
		);
		$command = new TrafficReaggregate($service);

		$output = new BufferedOutput();
		$this->assertSame(0, $command->run(new ArrayInput([]), $output));
		$text = $output->fetch();
		$this->assertStringContainsString('Days rebuilt: 5', $text);
		$this->assertStringContainsString('Roll-up days summed: 3', $text);

		$this->assertSame(0, $command->run(new ArrayInput(['--portal' => 'open-tilburg']), new BufferedOutput()));
		$this->assertSame([null, 'open-tilburg'], $asked);
	}//end testItBackfillsEveryPortalOrTheOneAskedFor()
}//end class
