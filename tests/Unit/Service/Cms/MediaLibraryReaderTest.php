<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A portal's published media, read once per cache window, and the published
 * pages that use one item.
 */
#[CoversClass(MediaLibraryReader::class)]
class MediaLibraryReaderTest extends TestCase {

	private ICache&MockObject $cache;
	private LoggerInterface&MockObject $logger;
	private PortalRegisterContext&MockObject $context;

	/**
	 * What findAll() was asked, per schema.
	 *
	 * @var list<array{string, array<string, mixed>}>
	 */
	private array $asked = [];

	/**
	 * Rows per schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $rows = [];

	protected function setUp(): void {
		$this->cache = $this->createMock(ICache::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->context = $this->createMock(PortalRegisterContext::class);
		$this->asked = [];
		$this->rows = [];
	}//end setUp()

	/**
	 * The reader over an ObjectService double serving $this->rows.
	 *
	 * @param bool $applies Whether the register context applies.
	 *
	 * @return MediaLibraryReader
	 */
	private function reader(bool $applies = true): MediaLibraryReader {
		$test = $this;
		$objects = new class ($test) {
			public string $schema = '';

			public function __construct(private MediaLibraryReaderTest $test) {
			}

			public function findAll(array $config, bool $_rbac, bool $_multitenancy): mixed {
				return $this->test->answer($this->schema, $config, $_rbac, $_multitenancy);
			}
		};
		$this->context->method('apply')->willReturnCallback(
			static function (object $service, string $schemaSlug) use ($applies): bool {
				$service->schema = $schemaSlug;
				return $applies;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objects);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->expects($this->once())->method('createDistributed')->with('portaliq_cms')->willReturn($this->cache);

		return new MediaLibraryReader($container, $factory, $this->logger, $this->context);
	}//end reader()

	/**
	 * The ObjectService double's answer.
	 *
	 * @param string               $schema        The schema the context applied.
	 * @param array<string, mixed> $config        The query.
	 * @param bool                 $rbac          RBAC flag.
	 * @param bool                 $multitenancy  Multitenancy flag.
	 *
	 * @return mixed
	 */
	public function answer(string $schema, array $config, bool $rbac, bool $multitenancy): mixed {
		$this->asked[] = [$schema, $config + ['_rbac' => $rbac, '_multitenancy' => $multitenancy]];
		$rows = $this->rows[$schema] ?? [];
		if ($rows instanceof \Throwable) {
			throw $rows;
		}
		return $rows;
	}//end answer()

	public function testPublishedItemsOfThePortalAreListedAndCached(): void {
		$this->rows['media'] = [
			['@self' => ['id' => 'm1'], 'portal' => 'gem', 'status' => 'published', 'title' => 'Hall', 'alt' => 'Town hall', 'kind' => 'image'],
			['uuid' => 'm2', 'portal' => 'gem', 'status' => 'published'],
			['id' => 'm3', 'portal' => 'other', 'status' => 'published'],
			['id' => 'm4', 'portal' => 'gem', 'status' => 'draft'],
			['portal' => 'gem', 'status' => 'published'],
			new class implements \JsonSerializable {
				public function jsonSerialize(): array {
					return ['id' => 'm5', 'portal' => 'gem', 'status' => 'published', 'title' => 'Entity'];
				}
			},
		];
		$expected = [
			'm1' => ['id' => 'm1', 'title' => 'Hall', 'alt' => 'Town hall', 'kind' => 'image'],
			'm2' => ['id' => 'm2', 'title' => '', 'alt' => '', 'kind' => 'file'],
			'm5' => ['id' => 'm5', 'title' => 'Entity', 'alt' => '', 'kind' => 'file'],
		];
		$this->cache->expects($this->once())->method('get')->with('gem|media|||anonymous')->willReturn(null);
		$this->cache->expects($this->once())->method('set')->with('gem|media|||anonymous', json_encode($expected), 300);

		$this->assertSame($expected, $this->reader()->items('gem'));
		$this->assertSame(
			[['media', ['filters' => ['portal' => 'gem', 'status' => 'published'], 'limit' => 500, 'offset' => 0, '_rbac' => false, '_multitenancy' => false]]],
			$this->asked
		);
	}//end testPublishedItemsOfThePortalAreListedAndCached()

	public function testACachedListIsServedWithoutAQuery(): void {
		$this->cache->method('get')->willReturn('{"m1":{"id":"m1","title":"Hall","alt":"","kind":"file"}}');
		$this->cache->expects($this->never())->method('set');

		$reader = $this->reader();

		$this->assertSame(['id' => 'm1', 'title' => 'Hall', 'alt' => '', 'kind' => 'file'], $reader->item('gem', 'm1'));
		$this->assertNull($reader->item('gem', 'm9'));
		$this->assertSame([], $this->asked);
	}//end testACachedListIsServedWithoutAQuery()

	public function testAnUnreadableCacheEntryIsAnEmptyList(): void {
		$this->cache->method('get')->willReturn('not json');

		$this->assertSame([], $this->reader()->items('gem'));
	}//end testAnUnreadableCacheEntryIsAnEmptyList()

	public function testAnItemNeedsAPortalAndAnId(): void {
		$this->cache->expects($this->never())->method('get');
		$reader = $this->reader();

		$this->assertNull($reader->item('', 'm1'));
		$this->assertNull($reader->item('gem', ''));
		$this->assertSame([], $reader->pagesUsing('', 'm1'));
		$this->assertSame([], $reader->pagesUsing('gem', ''));
		$this->assertSame([], $this->asked);
	}//end testAnItemNeedsAPortalAndAnId()

	public function testPagesUsingAnItemAreItsSortedRoutesMatchingTheWholeId(): void {
		$this->rows['page'] = [
			['route' => '/zz', 'heroImage' => 'media:m1'],
			['route' => '/aa', 'body' => ['blocks' => [['image' => 'media:m1']]]],
			['route' => '/seo', 'seoImage' => 'media:m1'],
			['route' => '/longer', 'heroImage' => 'media:m1-b'],
			['route' => '/none', 'body' => 'no media'],
		];

		$this->assertSame(['/aa', '/seo', '/zz'], $this->reader()->pagesUsing('gem', 'm1'));
		$this->assertSame('page', $this->asked[0][0]);
		$this->assertSame(['portal' => 'gem', 'status' => 'published'], $this->asked[0][1]['filters']);
	}//end testPagesUsingAnItemAreItsSortedRoutesMatchingTheWholeId()

	public function testNothingIsReadWhenTheRegisterIsNotSetUp(): void {
		$this->cache->method('get')->willReturn(null);

		$this->assertSame([], $this->reader(applies: false)->pagesUsing('gem', 'm1'));
		$this->assertSame([], $this->asked);
	}//end testNothingIsReadWhenTheRegisterIsNotSetUp()

	public function testAFailedReadIsLoggedAndEmpty(): void {
		$this->rows['page'] = new RuntimeException('db down');
		$this->logger->expects($this->once())->method('error')
			->with('Portaliq: media library read failed', ['schema' => 'page', 'reason' => 'db down']);

		$this->assertSame([], $this->reader()->pagesUsing('gem', 'm1'));
	}//end testAFailedReadIsLoggedAndEmpty()

	public function testANonListAnswerIsEmpty(): void {
		$this->rows['media'] = 'not rows';
		$this->cache->method('get')->willReturn(null);

		$this->assertSame([], $this->reader()->items('gem'));
	}//end testANonListAnswerIsEmpty()
}//end class
