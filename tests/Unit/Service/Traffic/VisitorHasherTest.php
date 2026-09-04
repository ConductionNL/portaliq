<?php

/**
 * Unit tests for VisitorHasher.
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

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\VisitorHasher;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

// The test tree is not autoloaded; the double is a plain include.
require_once __DIR__ . '/FakeAppConfig.php';

/**
 * A daily identity that cannot be joined across days.
 */
class VisitorHasherTest extends TestCase {


	/**
	 * A hasher whose clock says the given moment.
	 *
	 * @param FakeAppConfig $fake The shared config store.
	 * @param int           $time The Unix time.
	 *
	 * @return VisitorHasher The hasher.
	 */
	private function hasher(FakeAppConfig $fake, int $time): VisitorHasher {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn($time);

		return new VisitorHasher($fake->mock($this), $clock);
	}//end hasher()


	/**
	 * The same inputs hash the same within a day, differ per portal, and
	 * never contain the inputs.
	 *
	 * @return void
	 */
	public function testTheHashIsStableWithinADayAndScopedToThePortal(): void {
		$fake = new FakeAppConfig();
		$hasher = $this->hasher(fake: $fake, time: 1788000000);

		$one = $hasher->hash(portal: 'open-tilburg', parts: ['203.0.113.9', 'Mozilla/5.0']);
		$two = $hasher->hash(portal: 'open-tilburg', parts: ['203.0.113.9', 'Mozilla/5.0']);
		$other = $hasher->hash(portal: 'open-venray', parts: ['203.0.113.9', 'Mozilla/5.0']);

		$this->assertSame($one, $two);
		$this->assertNotSame($one, $other, 'the same browser on two portals is two visitors');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $one);
		$this->assertStringNotContainsString('203.0.113.9', $one);
	}//end testTheHashIsStableWithinADayAndScopedToThePortal()


	/**
	 * A new day gets a new salt, and yesterday's salt is DELETED.
	 *
	 * The deletion is the privacy property. If the old salt stayed in config,
	 * anyone holding the table and the config could recompute yesterday's
	 * hashes from today's visitors and join the days into a person.
	 *
	 * @return void
	 */
	public function testTheSaltRotatesAndTheOldOneIsDeleted(): void {
		$fake = new FakeAppConfig();
		$yesterday = $this->hasher(fake: $fake, time: 1788000000)->hash(portal: 'p', parts: ['a']);
		$saltKeys = static fn (array $values): array => array_values(array_filter(
			array_keys($values),
			static fn (string $key): bool => str_contains($key, VisitorHasher::SALT_PREFIX)
		));
		$this->assertCount(1, $saltKeys($fake->values));

		$today = $this->hasher(fake: $fake, time: 1788000000 + 86400)->hash(portal: 'p', parts: ['a']);

		$this->assertNotSame($yesterday, $today);
		$this->assertCount(1, $saltKeys($fake->values), 'exactly one salt survives: today\'s');
		$this->assertStringContainsString(gmdate('Ymd', 1788000000 + 86400), $saltKeys($fake->values)[0]);
	}//end testTheSaltRotatesAndTheOldOneIsDeleted()


}//end class
