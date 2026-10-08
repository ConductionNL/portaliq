<?php

/**
 * Tests for the per-identity and per-case citizen write throttle.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CitizenWriteThrottle;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The framework limits by address, which is the wrong unit for a portal write.
 * This counts by identity and by case, and it fails open when there is no
 * counter rather than refusing every citizen because memcache is down.
 *
 * @covers \OCA\Portaliq\Service\CitizenWriteThrottle
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenWriteThrottleTest extends TestCase {
	/**
	 * One identity writing on one case is stopped at the per-case limit, and
	 * the write that was refused is itself counted, so being refused buys no
	 * extra budget.
	 */
	public function testOneCaseIsStoppedAtThePerCaseLimit(): void {
		$throttle = new CitizenWriteThrottle($this->cacheFactory());

		$allowed = 0;
		for ($attempt = 0; $attempt < 25; $attempt++) {
			if ($throttle->allow(subjectRef: 's1', caseId: 'zaak-1') === true) {
				$allowed++;
			}
		}

		$this->assertSame(20, $allowed);
		$this->assertFalse($throttle->allow(subjectRef: 's1', caseId: 'zaak-1'));
	}//end testOneCaseIsStoppedAtThePerCaseLimit()

	/**
	 * A second case gets its own budget: one busy case never locks a citizen
	 * out of another one.
	 */
	public function testASecondCaseHasItsOwnBudget(): void {
		$throttle = new CitizenWriteThrottle($this->cacheFactory());

		for ($attempt = 0; $attempt < 21; $attempt++) {
			$throttle->allow(subjectRef: 's1', caseId: 'zaak-1');
		}

		$this->assertFalse($throttle->allow(subjectRef: 's1', caseId: 'zaak-1'));
		$this->assertTrue($throttle->allow(subjectRef: 's1', caseId: 'zaak-2'));
	}//end testASecondCaseHasItsOwnBudget()

	/**
	 * Spreading the writes over many cases still hits the identity limit, so a
	 * per-case budget cannot be multiplied by opening more cases.
	 */
	public function testTheIdentityLimitHoldsAcrossCases(): void {
		$throttle = new CitizenWriteThrottle($this->cacheFactory());

		$allowed = 0;
		for ($attempt = 0; $attempt < 70; $attempt++) {
			if ($throttle->allow(subjectRef: 's1', caseId: 'zaak-' . $attempt) === true) {
				$allowed++;
			}
		}

		$this->assertSame(60, $allowed);
	}//end testTheIdentityLimitHoldsAcrossCases()

	/**
	 * Another identity is unaffected by the first one's budget.
	 */
	public function testAnotherIdentityKeepsItsOwnBudget(): void {
		$throttle = new CitizenWriteThrottle($this->cacheFactory());

		for ($attempt = 0; $attempt < 70; $attempt++) {
			$throttle->allow(subjectRef: 's1', caseId: 'zaak-1');
		}

		$this->assertTrue($throttle->allow(subjectRef: 's2', caseId: 'zaak-1'));
	}//end testAnotherIdentityKeepsItsOwnBudget()

	/**
	 * A session with no subject reference is refused outright: there is no
	 * unit to count, and an uncountable write is not a citizen's write.
	 */
	public function testAWriteWithNoIdentityIsRefused(): void {
		$this->assertFalse((new CitizenWriteThrottle($this->cacheFactory()))->allow(subjectRef: '', caseId: 'zaak-1'));
	}//end testAWriteWithNoIdentityIsRefused()

	/**
	 * With no usable cache the throttle stands aside, because the framework's
	 * own rate limit is still underneath it and refusing everyone would refuse
	 * the wrong people.
	 */
	public function testNoCacheFailsOpenRatherThanRefusingEveryone(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new RuntimeException('no cache'));

		$this->assertTrue((new CitizenWriteThrottle($factory))->allow(subjectRef: 's1', caseId: 'zaak-1'));
	}//end testNoCacheFailsOpenRatherThanRefusingEveryone()

	/**
	 * An in-memory stand-in for the distributed cache.
	 */
	private function cacheFactory(): ICacheFactory {
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			static function (string $key) use (&$store) {
				return ($store[$key] ?? null);
			}
		);
		$cache->method('set')->willReturnCallback(
			static function (string $key, $value) use (&$store): bool {
				$store[$key] = $value;

				return true;
			}
		);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return $factory;
	}//end cacheFactory()
}//end class
