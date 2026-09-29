<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\Cms\MediaReferences;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * site-page-seo-history-and-media T07, T08 (REQ-SPH-004, REQ-SPH-005): a
 * page refers to a library item as media:<id>, and the content API serves the
 * item's public address with its alternative text. Only a published item of
 * the page's own portal resolves.
 *
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */
class CmsReaderMediaTest extends TestCase {

	private const IMAGE = '0b6e7c1e-0000-4000-8000-00000000000a';

	private const DRAFT = '0b6e7c1e-0000-4000-8000-00000000000b';

	public function testTheHeroImageFromTheLibraryCarriesItsAddressAndAlternativeText(): void {
		$page = $this->reader(pages: [$this->page(['heroImage' => 'media:'.self::IMAGE])])
			->page(portal: 'gemeente', route: '/contact', locale: 'nl', audience: 'anonymous');

		$this->assertSame(
			['url' => 'https://gemeente.example/media/'.self::IMAGE.'?portal=gemeente', 'alt' => 'Het stadhuis aan de Markt'],
			$page['hero']
		);
	}//end testTheHeroImageFromTheLibraryCarriesItsAddressAndAlternativeText()

	public function testAnAddressHeroImageIsServedAsIs(): void {
		$page = $this->reader(pages: [$this->page(['heroImage' => 'https://example.nl/a.jpg'])])
			->page(portal: 'gemeente', route: '/contact', locale: 'nl', audience: 'anonymous');

		$this->assertSame(['url' => 'https://example.nl/a.jpg', 'alt' => ''], $page['hero']);
	}//end testAnAddressHeroImageIsServedAsIs()

	public function testADraftOrUnknownItemLendsNothing(): void {
		$draft = $this->reader(pages: [$this->page(['heroImage' => 'media:'.self::DRAFT, 'seoImage' => 'media:nope'])])
			->page(portal: 'gemeente', route: '/contact', locale: 'nl', audience: 'anonymous');

		$this->assertNull($draft['hero']);
		$this->assertSame('', $draft['seo']['image']);
	}//end testADraftOrUnknownItemLendsNothing()

	public function testTheShareImageAndMarkdownReferencesResolve(): void {
		$page = $this->reader(pages: [$this->page([
			'seoImage' => 'media:'.self::IMAGE,
			'body' => ['type' => 'markdown', 'markdown' => "Tekst\n\n![Stadhuis](media:".self::IMAGE.")\n\n![Concept](media:".self::DRAFT.')'],
		])])->page(portal: 'gemeente', route: '/contact', locale: 'nl', audience: 'anonymous');

		$url = 'https://gemeente.example/media/'.self::IMAGE.'?portal=gemeente';
		$this->assertSame($url, $page['seo']['image']);
		$this->assertSame("Tekst\n\n![Stadhuis](".$url.")\n\n![Concept]()", $page['body']['markdown']);
	}//end testTheShareImageAndMarkdownReferencesResolve()

	public function testOnlyThePortalsPublishedItemsAreRead(): void {
		$reader = $this->reader(pages: []);

		$this->assertSame(['id' => self::IMAGE, 'title' => 'Stadhuis', 'alt' => 'Het stadhuis aan de Markt', 'kind' => 'image'], $reader->mediaItem(portal: 'gemeente', id: self::IMAGE));
		$this->assertNull($reader->mediaItem(portal: 'gemeente', id: self::DRAFT));
		$this->assertSame(['portal' => 'gemeente', 'status' => 'published'], $this->queried['media']);
	}//end testOnlyThePortalsPublishedItemsAreRead()

	/**
	 * The filters each schema was last queried with.
	 *
	 * @var array<string, array>
	 */
	public array $queried = [];

	/**
	 * A stored page on /contact.
	 *
	 * @param array<string, mixed> $fields Extra fields.
	 *
	 * @return array<string, mixed>
	 */
	private function page(array $fields): array {
		return $fields + ['title' => 'Contact', 'route' => '/contact', 'portal' => 'gemeente', 'status' => 'published', 'body' => ['type' => 'markdown', 'markdown' => '']];
	}//end page()

	/**
	 * A reader over an object service answering per schema.
	 *
	 * @param array<array> $pages The stored pages.
	 *
	 * @return CmsReader
	 */
	private function reader(array $pages): CmsReader {
		$media = [
			['id' => self::IMAGE, 'portal' => 'gemeente', 'title' => 'Stadhuis', 'kind' => 'image', 'status' => 'published', 'alt' => 'Het stadhuis aan de Markt'],
		];
		$test = $this;
		$objects = new class(['page' => $pages, 'media' => $media], $test) {

			public string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array> $rows The rows per schema.
			 * @param object               $test The test, for recording.
			 */
			public function __construct(private array $rows, private object $test) {
			}

			/**
			 * The rows of the schema in context.
			 *
			 * @param array $config        The query.
			 * @param bool  $_rbac         RBAC.
			 * @param bool  $_multitenancy Multitenancy.
			 *
			 * @return array
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->queried[$this->schema] = $config['filters'];
				return $this->rows[$this->schema] ?? [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(
			static function (object $service, string $schemaSlug): bool {
				$service->schema = $schemaSlug;
				return true;
			}
		);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params) => 'https://gemeente.example/media/'.$params['id'].'?portal='.$params['portal']
		);

		return new CmsReader($container, $factory, new NullLogger(), $context, new MediaReferences($urls));
	}//end reader()
}//end class
