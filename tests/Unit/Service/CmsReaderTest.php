<?php

/**
 * CmsReaderTest
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

use OCA\Portaliq\Service\CmsReader;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The cache key is the thing worth testing here.
 *
 * Everything else in CmsReader is shaping; the key is the one line where a
 * mistake becomes a cross-visitor data leak rather than a rendering bug, and
 * where nothing on screen would look wrong.
 */
class CmsReaderTest extends TestCase {

	/**
	 * The reader under test.
	 *
	 * @var CmsReader
	 */
	private CmsReader $reader;

	/**
	 * The cache double.
	 *
	 * @var ICache
	 */
	private ICache $cache;


	/**
	 * Build the reader with doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cache = $this->createMock(ICache::class);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache);

		$this->reader = new CmsReader(
			$this->createMock(ContainerInterface::class),
			$factory,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()


	/**
	 * The audience must change the key.
	 *
	 * THIS IS THE CONTROL. If `cacheKey()` ever stops including the audience,
	 * these two calls collapse onto one cache entry and an authenticated
	 * visitor's page is served to anonymous ones. Nothing about the site would
	 * look wrong; the only signal is this assertion.
	 *
	 * @return void
	 */
	public function testAudienceIsPartOfTheCacheKey(): void {
		$anonymous = $this->reader->cacheKey('open-tilburg', 'page', '/over-ons', 'nl', 'anonymous');
		$authenticated = $this->reader->cacheKey('open-tilburg', 'page', '/over-ons', 'nl', 'authenticated');

		$this->assertNotSame(
			$anonymous,
			$authenticated,
			'Anonymous and authenticated reads MUST NOT share a cache entry. '
			. 'If this fails, one visitor\'s page is being served to everybody.'
		);
	}//end testAudienceIsPartOfTheCacheKey()


	/**
	 * The website must change the key.
	 *
	 * @return void
	 */
	public function testWebsiteIsPartOfTheCacheKey(): void {
		$this->assertNotSame(
			$this->reader->cacheKey('open-tilburg', 'page', '/over-ons', 'nl', 'anonymous'),
			$this->reader->cacheKey('open-venray', 'page', '/over-ons', 'nl', 'anonymous'),
			'Two sites publishing the same route MUST NOT share a cache entry.'
		);
	}//end testWebsiteIsPartOfTheCacheKey()


	/**
	 * The locale must change the key.
	 *
	 * @return void
	 */
	public function testLocaleIsPartOfTheCacheKey(): void {
		$this->assertNotSame(
			$this->reader->cacheKey('open-tilburg', 'page', '/over-ons', 'nl', 'anonymous'),
			$this->reader->cacheKey('open-tilburg', 'page', '/over-ons', 'en', 'anonymous'),
			'A translated page MUST NOT be served under the wrong locale.'
		);
	}//end testLocaleIsPartOfTheCacheKey()


	/**
	 * Identical inputs must produce an identical key.
	 *
	 * Without this, the three assertions above would also pass for a key that
	 * was simply random — and a cache that never hits is indistinguishable
	 * from no cache at all except in the metrics nobody reads.
	 *
	 * @return void
	 */
	public function testIdenticalInputsProduceIdenticalKeys(): void {
		$this->assertSame(
			$this->reader->cacheKey('open-tilburg', 'page', '/over-ons', 'nl', 'anonymous'),
			$this->reader->cacheKey('open-tilburg', 'page', '/over-ons', 'nl', 'anonymous')
		);
	}//end testIdenticalInputsProduceIdenticalKeys()


	/**
	 * Invalidation must clear by website prefix.
	 *
	 * The prefix clear is what reaches per-route page entries, whose keys
	 * cannot be enumerated from here. Those entries include NEGATIVE results,
	 * so a missed prefix clear leaves a newly created route 404ing until its
	 * TTL expires.
	 *
	 * @return void
	 */
	public function testInvalidateClearsTheWebsitePrefix(): void {
		$this->cache->expects($this->once())
			->method('clear')
			->with('open-tilburg|');

		$this->reader->invalidate('open-tilburg');
	}//end testInvalidateClearsTheWebsitePrefix()


	/**
	 * An unscoped query must return nothing rather than everything.
	 *
	 * A read with no website is not a broad read — it is a read that would
	 * serve one tenant's content under another's domain. Failing closed here
	 * is the difference between an empty page and a cross-tenant leak.
	 *
	 * @return void
	 */
	public function testAnUnscopedReadReturnsNothing(): void {
		$this->cache->method('get')->willReturn(null);

		$this->assertSame([], $this->reader->menus('', 'nl', 'anonymous'));
		$this->assertSame([], $this->reader->pages('', 'nl', 'anonymous'));
		$this->assertSame([], $this->reader->glossary('', 'nl', 'anonymous'));
		$this->assertNull($this->reader->page('', '/', 'nl', 'anonymous'));
	}//end testAnUnscopedReadReturnsNothing()


}//end class
