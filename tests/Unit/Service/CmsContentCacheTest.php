<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\Cms\MediaReferences;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * portal-headless-content-api tasks 2 and 3, through the real read path with a
 * working cache: audiences never share an entry, a 404 is cached and a
 * write drops it, and the cache says how often it answered.
 *
 * @spec openspec/changes/portal-headless-content-api/tasks.md#task-2
 */
class CmsContentCacheTest extends TestCase {
	/**
	 * The rows the object service holds, and how often it was asked.
	 *
	 * @var object
	 */
	private object $store;

	private CmsReader $reader;

	protected function setUp(): void {
		parent::setUp();

		$cache = new class implements ICache {
			/**
			 * @var array<string, mixed>
			 */
			public array $data = [];

			public function get($key) {
				return ($this->data[$key] ?? null);
			}

			public function set($key, $value, $ttl = 0) {
				$this->data[$key] = $value;
				return true;
			}

			public function hasKey($key) {
				return isset($this->data[$key]);
			}

			public function remove($key) {
				unset($this->data[$key]);
				return true;
			}

			public function clear($prefix = '') {
				foreach (array_keys($this->data) as $key) {
					if ($prefix === '' || str_starts_with((string)$key, $prefix) === true) {
						unset($this->data[$key]);
					}
				}

				return true;
			}

			public static function isAvailable(): bool {
				return true;
			}
		};

		$this->store = new class {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $rows = [];

			public int $queries = 0;

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries++;
				$filters = (array)($config['filters'] ?? []);

				return array_values(
					array_filter(
						$this->rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		$this->reader = new CmsReader(
			$container,
			$factory,
			$this->createMock(LoggerInterface::class),
			$context,
			new MediaReferences($this->createMock(IURLGenerator::class), $this->createMock(MediaLibraryReader::class))
		);
	}//end setUp()

	/**
	 * @return array<string, mixed>
	 */
	private function page(string $route = '/over-ons'): array {
		return ['title' => 'Over ons', 'route' => $route, 'status' => 'published', 'portal' => 'gemeente', 'body' => ['type' => 'markdown', 'markdown' => 'Hallo']];
	}//end page()

	public function testAnonymousAndAuthenticatedReadsNeverShareAnEntry(): void {
		$this->store->rows = [$this->page()];

		$this->reader->page('gemeente', '/over-ons', 'nl', 'anonymous');
		$this->assertSame(1, $this->store->queries);

		$this->reader->page('gemeente', '/over-ons', 'nl', 'anonymous');
		$this->assertSame(1, $this->store->queries, 'a second anonymous read is served from the cache');

		$this->reader->page('gemeente', '/over-ons', 'nl', 'authenticated');
		$this->assertSame(2, $this->store->queries, 'an authenticated read does not reuse the anonymous entry');
	}//end testAnonymousAndAuthenticatedReadsNeverShareAnEntry()

	public function testANotFoundIsCachedAndAWriteMakesTheNewPageVisibleAtOnce(): void {
		$this->assertNull($this->reader->page('gemeente', '/nieuw', 'nl', 'anonymous'));

		$this->store->rows = [$this->page('/nieuw')];
		$this->assertNull($this->reader->page('gemeente', '/nieuw', 'nl', 'anonymous'), 'the 404 is cached until a write');

		$this->reader->invalidate('gemeente');

		$this->assertSame('Over ons', $this->reader->page('gemeente', '/nieuw', 'nl', 'anonymous')['title'] ?? null);
	}//end testANotFoundIsCachedAndAWriteMakesTheNewPageVisibleAtOnce()

	public function testAMenuWriteDropsThePageEntriesOfThatPortalOnly(): void {
		$this->store->rows = [$this->page(), ['title' => 'Elders', 'route' => '/elders', 'status' => 'published', 'portal' => 'andere', 'body' => ['type' => 'markdown', 'markdown' => 'x']]];
		$this->reader->page('gemeente', '/over-ons', 'nl', 'anonymous');
		$this->reader->page('andere', '/elders', 'nl', 'anonymous');
		$this->assertSame(2, $this->store->queries);

		$this->reader->invalidate('gemeente');

		$this->reader->page('andere', '/elders', 'nl', 'anonymous');
		$this->assertSame(2, $this->store->queries, 'the other portal keeps its entry');
		$this->reader->page('gemeente', '/over-ons', 'nl', 'anonymous');
		$this->assertSame(3, $this->store->queries, 'the written portal is read again');
	}//end testAMenuWriteDropsThePageEntriesOfThatPortalOnly()

	public function testTheCacheCountsItsHitsAndMisses(): void {
		$this->store->rows = [$this->page()];
		$this->assertSame(['hits' => 0, 'misses' => 0], $this->reader->cacheStats());

		$this->reader->page('gemeente', '/over-ons', 'nl', 'anonymous');
		$this->reader->page('gemeente', '/over-ons', 'nl', 'anonymous');
		$this->reader->page('gemeente', '/over-ons', 'nl', 'anonymous');

		$this->assertSame(['hits' => 2, 'misses' => 1], $this->reader->cacheStats());

		$this->reader->invalidate('gemeente');
		$this->assertSame(['hits' => 2, 'misses' => 1], $this->reader->cacheStats(), 'an invalidation does not reset the counts');
	}//end testTheCacheCountsItsHitsAndMisses()
}//end class
