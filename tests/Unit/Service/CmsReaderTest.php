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
	 * The container double, wired per-test by withRows().
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;


	/**
	 * Build the reader with doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cache = $this->createMock(ICache::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache);

		$this->reader = new CmsReader(
			$this->container,
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
	 * The portal must change the key.
	 *
	 * @return void
	 */
	public function testPortalIsPartOfTheCacheKey(): void {
		$this->assertNotSame(
			$this->reader->cacheKey('open-tilburg', 'page', '/over-ons', 'nl', 'anonymous'),
			$this->reader->cacheKey('open-venray', 'page', '/over-ons', 'nl', 'anonymous'),
			'Two sites publishing the same route MUST NOT share a cache entry.'
		);
	}//end testPortalIsPartOfTheCacheKey()


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
	 * Invalidation must clear by portal prefix.
	 *
	 * The prefix clear is what reaches per-route page entries, whose keys
	 * cannot be enumerated from here. Those entries include NEGATIVE results,
	 * so a missed prefix clear leaves a newly created route 404ing until its
	 * TTL expires.
	 *
	 * @return void
	 */
	public function testInvalidateClearsThePortalPrefix(): void {
		$this->cache->expects($this->once())
			->method('clear')
			->with('open-tilburg|');

		$this->reader->invalidate('open-tilburg');
	}//end testInvalidateClearsThePortalPrefix()


	/**
	 * An unscoped query must return nothing rather than everything.
	 *
	 * A read with no portal is not a broad read — it is a read that would
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


	/**
	 * Wire the container to an ObjectService double returning fixed rows.
	 *
	 * @param array $rows The rows findAll() should return.
	 *
	 * @return void
	 */
	private function withRows(array $rows): void {
		$objectService = new class($rows) {

			/**
			 * The rows to return.
			 *
			 * @var array
			 */
			public array $rows;


			/**
			 * Constructor.
			 *
			 * @param array $rows The rows.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}


			/**
			 * Set the register context.
			 *
			 * @param string $register The register slug.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
			}


			/**
			 * Set the schema context.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
			}


			/**
			 * Return the fixed rows.
			 *
			 * @param array $config         The query config.
			 * @param bool  $_rbac          RBAC toggle.
			 * @param bool  $_multitenancy  Multitenancy toggle.
			 *
			 * @return array The rows.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->rows;
			}


		};

		$this->container->method('get')->willReturn($objectService);
		$this->cache->method('get')->willReturn(null);
	}//end withRows()


	/**
	 * Menus come back ordered, with exactly two levels.
	 *
	 * A third level in stored data is DROPPED rather than passed on, so a
	 * consumer never has to guess how deep the tree can go.
	 *
	 * @return void
	 */
	public function testMenusAreOrderedAndTruncatedToTwoLevels(): void {
		$this->withRows(
			[
				[
					'title' => 'Second',
					'position' => 5,
					'items' => [],
				],
				[
					'title' => 'First',
					'position' => 1,
					'items' => [
						[
							'order' => 2,
							'name' => 'B',
							'link' => '/b',
						],
						[
							'order' => 1,
							'name' => 'A',
							'link' => '/a',
							'items' => [
								[
									'order' => 2,
									'name' => 'A2',
									'link' => '/a2',
									'items' => [['name' => 'TOO DEEP']],
								],
								[
									'order' => 1,
									'name' => 'A1',
									'link' => '/a1',
								],
							],
						],
					],
				],
			]
		);

		$menus = $this->reader->menus(portal: 'open-tilburg', locale: 'nl', audience: 'anonymous');

		$this->assertSame(['First', 'Second'], array_column($menus, 'title'));
		$this->assertSame(['A', 'B'], array_column($menus[0]['items'], 'name'));
		$this->assertSame(['A1', 'A2'], array_column($menus[0]['items'][0]['items'], 'name'));
		$this->assertArrayNotHasKey('items', $menus[0]['items'][0]['items'][0]);
	}//end testMenusAreOrderedAndTruncatedToTwoLevels()


	/**
	 * A markdown page is returned as source, not converted.
	 *
	 * @return void
	 */
	public function testAMarkdownPageIsReturnedAsSource(): void {
		$markdown = "## Kop\n\n```php\n<?php echo 1;\n```\n";
		$this->withRows(
			[
				[
					'title' => 'Over ons',
					'route' => '/over-ons',
					'body' => [
						'type' => 'markdown',
						'markdown' => $markdown,
					],
				],
			]
		);

		$page = $this->reader->page(
			portal: 'open-tilburg',
			route: '/over-ons',
			locale: 'nl',
			audience: 'anonymous'
		);

		$this->assertSame('markdown', $page['body']['type']);
		$this->assertSame($markdown, $page['body']['markdown']);
		$this->assertArrayNotHasKey('widgets', $page['body']);
	}//end testAMarkdownPageIsReturnedAsSource()


	/**
	 * Grid widgets are ordered by row then column.
	 *
	 * Reading order, so a consumer that ignores the coordinates entirely still
	 * renders something sensible.
	 *
	 * @return void
	 */
	public function testGridWidgetsAreOrderedByRowThenColumn(): void {
		$this->withRows(
			[
				[
					'title' => 'Home',
					'route' => '/',
					'body' => [
						'type' => 'grid',
						'widgets' => [
							[
								'id' => 'c',
								'widgetKey' => 'markdown',
								'gridX' => 6,
								'gridY' => 1,
								'gridWidth' => 6,
								'gridHeight' => 2,
							],
							[
								'id' => 'a',
								'widgetKey' => 'markdown',
								'gridX' => 0,
								'gridY' => 0,
								'gridWidth' => 12,
								'gridHeight' => 2,
							],
							[
								'id' => 'b',
								'widgetKey' => 'markdown',
								'gridX' => 0,
								'gridY' => 1,
								'gridWidth' => 6,
								'gridHeight' => 2,
							],
						],
					],
				],
			]
		);

		$page = $this->reader->page(
			portal: 'open-tilburg',
			route: '/',
			locale: 'nl',
			audience: 'anonymous'
		);

		$this->assertSame(['a', 'b', 'c'], array_column($page['body']['widgets'], 'id'));
	}//end testGridWidgetsAreOrderedByRowThenColumn()


	/**
	 * A route that does not match returns null, not the first row.
	 *
	 * The query filters by route, but the match is re-checked here: a filter
	 * that silently widened would otherwise serve a DIFFERENT page under the
	 * requested route, which renders perfectly and is wrong.
	 *
	 * @return void
	 */
	public function testANonMatchingRouteReturnsNull(): void {
		$this->withRows(
			[
				[
					'title' => 'Something else',
					'route' => '/elsewhere',
					'body' => ['type' => 'markdown'],
				],
			]
		);

		$this->assertNull(
			$this->reader->page(
				portal: 'open-tilburg',
				route: '/over-ons',
				locale: 'nl',
				audience: 'anonymous'
			)
		);
	}//end testANonMatchingRouteReturnsNull()


	/**
	 * Page summaries are route-sorted and carry no body.
	 *
	 * @return void
	 */
	public function testPageSummariesAreSortedAndBodyless(): void {
		$this->withRows(
			[
				[
					'title' => 'Zeta',
					'route' => '/zeta',
					'body' => ['type' => 'markdown', 'markdown' => 'secret-ish'],
				],
				[
					'title' => 'Alpha',
					'route' => '/alpha',
					'body' => ['type' => 'grid'],
				],
			]
		);

		$pages = $this->reader->pages(portal: 'open-tilburg', locale: 'nl', audience: 'anonymous');

		$this->assertSame(['/alpha', '/zeta'], array_column($pages, 'route'));
		$this->assertSame(['grid', 'markdown'], array_column($pages, 'bodyType'));
		$this->assertArrayNotHasKey('body', $pages[0]);
	}//end testPageSummariesAreSortedAndBodyless()


	/**
	 * Glossary terms come back alphabetically, case-insensitively.
	 *
	 * @return void
	 */
	public function testGlossaryTermsAreAlphabetical(): void {
		$this->withRows(
			[
				['term' => 'zaak', 'definition' => 'z'],
				['term' => 'Aanvraag', 'definition' => 'a'],
				['term' => 'besluit', 'definition' => 'b'],
			]
		);

		$terms = $this->reader->glossary(portal: 'open-tilburg', locale: 'nl', audience: 'anonymous');

		$this->assertSame(['Aanvraag', 'besluit', 'zaak'], array_column($terms, 'term'));
	}//end testGlossaryTermsAreAlphabetical()


	/**
	 * A cached read does not query at all.
	 *
	 * @return void
	 */
	public function testACachedReadSkipsTheQuery(): void {
		$this->cache->method('get')->willReturn(json_encode([['title' => 'from cache', 'position' => 0, 'items' => []]]));
		$this->container->expects($this->never())->method('get');

		$menus = $this->reader->menus(portal: 'open-tilburg', locale: 'nl', audience: 'anonymous');

		$this->assertSame('from cache', $menus[0]['title']);
	}//end testACachedReadSkipsTheQuery()


}//end class
