<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalEmbedThrottle;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * embedded-intake-form: a burst from one origin, or from one address, is
 * refused rather than queued as cases. A refused submission is itself counted,
 * so being refused buys no fresh budget.
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */
class PortalEmbedThrottleTest extends TestCase {

	/**
	 * The fake counter store.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	protected function setUp(): void {
		$this->store = [];

	}//end setUp()

	public function testAnOrdinarySubmissionIsAllowed(): void {
		$throttle = new PortalEmbedThrottle($this->cacheFactory());

		$this->assertTrue($throttle->allow(origin: 'https://www.gemeente.nl', address: '203.0.113.10'));

	}//end testAnOrdinarySubmissionIsAllowed()

	public function testABurstFromOneAddressIsRefused(): void {
		$throttle = new PortalEmbedThrottle($this->cacheFactory());
		for ($attempt = 0; $attempt < 20; $attempt++) {
			$this->assertTrue($throttle->allow(origin: 'https://www.gemeente.nl', address: '203.0.113.10'));
		}

		$this->assertFalse($throttle->allow(origin: 'https://www.gemeente.nl', address: '203.0.113.10'));

	}//end testABurstFromOneAddressIsRefused()

	public function testABurstFromOneOriginIsRefusedEvenAcrossAddresses(): void {
		$throttle = new PortalEmbedThrottle($this->cacheFactory());
		for ($attempt = 0; $attempt < 120; $attempt++) {
			$throttle->allow(origin: 'https://www.gemeente.nl', address: 'address-' . $attempt);
		}

		$this->assertFalse($throttle->allow(origin: 'https://www.gemeente.nl', address: 'a-fresh-address'));

	}//end testABurstFromOneOriginIsRefusedEvenAcrossAddresses()

	public function testARefusedSubmissionIsStillCounted(): void {
		$throttle = new PortalEmbedThrottle($this->cacheFactory());
		for ($attempt = 0; $attempt < 25; $attempt++) {
			$throttle->allow(origin: 'https://www.gemeente.nl', address: '203.0.113.10');
		}

		// Five of those were already refusals; the counter kept counting, so
		// the address does not come back into budget by being refused.
		$this->assertFalse($throttle->allow(origin: 'https://www.gemeente.nl', address: '203.0.113.10'));

	}//end testARefusedSubmissionIsStillCounted()

	public function testAnotherOriginHasItsOwnBudget(): void {
		$throttle = new PortalEmbedThrottle($this->cacheFactory());
		for ($attempt = 0; $attempt < 30; $attempt++) {
			$throttle->allow(origin: 'https://www.gemeente.nl', address: '203.0.113.10');
		}

		$this->assertTrue($throttle->allow(origin: 'https://www.andere-gemeente.nl', address: '198.51.100.7'));

	}//end testAnotherOriginHasItsOwnBudget()

	public function testWithNoUsableCacheTheFrameworkLimitIsTheFloor(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new \RuntimeException('no cache'));

		$this->assertTrue((new PortalEmbedThrottle($factory))->allow(origin: 'https://www.gemeente.nl', address: '203.0.113.10'));

	}//end testWithNoUsableCacheTheFrameworkLimitIsTheFloor()

	/**
	 * A cache factory over an in-memory store.
	 *
	 * @return ICacheFactory
	 */
	private function cacheFactory(): ICacheFactory {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->store[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return $factory;
	}//end cacheFactory()

}//end class
