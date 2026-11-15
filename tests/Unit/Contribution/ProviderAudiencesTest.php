<?php

/**
 * Unit tests for reading the audiences a contribution provider serves.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ProviderAudiences;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `getAudiences()` is preferred over the single `getAudience()`, and anything
 * that is not a non-empty string is not an audience.
 */
#[CoversClass(ProviderAudiences::class)]
class ProviderAudiencesTest extends TestCase {

	/**
	 * A multi-audience provider serves its non-empty string audiences, even when it also names one.
	 *
	 * @return void
	 */
	public function testAMultiAudienceProvider(): void {
		$provider = new class {
			/**
			 * @return array<int,mixed>
			 */
			public function getAudiences(): array {
				return ['citizen', '', 7, 'business'];
			}

			/**
			 * @return string
			 */
			public function getAudience(): string {
				return 'supplier';
			}
		};
		$audiences = new ProviderAudiences();

		$this->assertSame(['citizen', 'business'], $audiences->served(provider: $provider));
		$this->assertTrue($audiences->serves(provider: $provider, audience: 'business'));
		$this->assertFalse($audiences->serves(provider: $provider, audience: 'supplier'));
	}//end testAMultiAudienceProvider()

	/**
	 * A provider whose `getAudiences()` does not return a list serves nobody.
	 *
	 * @return void
	 */
	public function testANonListServesNobody(): void {
		$provider = new class {
			/**
			 * @return string
			 */
			public function getAudiences(): string {
				return 'citizen';
			}
		};
		$audiences = new ProviderAudiences();

		$this->assertSame([], $audiences->served(provider: $provider));
		$this->assertFalse($audiences->serves(provider: $provider, audience: 'citizen'));
	}//end testANonListServesNobody()

	/**
	 * A single-audience provider serves exactly that audience, unless it is empty.
	 *
	 * @return void
	 */
	public function testASingleAudienceProvider(): void {
		$single = new class {
			/**
			 * @return string
			 */
			public function getAudience(): string {
				return 'supplier';
			}
		};
		$empty = new class {
			/**
			 * @return string
			 */
			public function getAudience(): string {
				return '';
			}
		};
		$audiences = new ProviderAudiences();

		$this->assertSame(['supplier'], $audiences->served(provider: $single));
		$this->assertTrue($audiences->serves(provider: $single, audience: 'supplier'));
		$this->assertFalse($audiences->serves(provider: $single, audience: 'citizen'));
		$this->assertSame([], $audiences->served(provider: $empty));
		$this->assertTrue($audiences->serves(provider: $empty, audience: ''));
	}//end testASingleAudienceProvider()

	/**
	 * A provider that names no audience serves nobody.
	 *
	 * @return void
	 */
	public function testAProviderWithoutAudiences(): void {
		$audiences = new ProviderAudiences();

		$this->assertSame([], $audiences->served(provider: new \stdClass()));
		$this->assertFalse($audiences->serves(provider: new \stdClass(), audience: 'citizen'));
	}//end testAProviderWithoutAudiences()
}//end class
