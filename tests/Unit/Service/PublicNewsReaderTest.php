<?php

/**
 * PublicNewsReader tests: only items staff put on THIS portal's website,
 * never one about single children, never a photo from outside the portal's
 * own published media library, newest first.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCA\Portaliq\Service\Cms\MediaReferences;
use OCA\Portaliq\Service\PublicNewsReader;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */
class PublicNewsReaderTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * A reader over these stored rows, with one published library item `m1`
	 * on portal `wilgenboom`.
	 *
	 * @param array<int, array<string, mixed>> $rows The newsItem rows.
	 *
	 * @return PublicNewsReader
	 */
	private function reader(array $rows): PublicNewsReader {
		$objectService = new class($rows) {
			/**
			 * @param array<int, array<string, mixed>> $rows The rows.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->rows;
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: '.$id);
			}
		);

		$library = $this->createMock(MediaLibraryReader::class);
		$library->method('item')->willReturnCallback(
			static fn (string $portal, string $id): ?array => ($portal === 'wilgenboom' && $id === 'm1') ? ['id' => 'm1', 'alt' => 'Kinderen lezen voor'] : null
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args): string => 'https://school.example/media/'.$args['id'].'?portal='.$args['portal']
		);

		return new PublicNewsReader($container, new MediaReferences($urls, $library), $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * A published public item of portal `wilgenboom`, with overrides.
	 *
	 * @param array<string, mixed> $overrides The changes.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $overrides = []): array {
		return $overrides + [
			'id'            => 'n1',
			'title'         => 'De Kinderboekenweek is begonnen',
			'body'          => "Groep 8 las voor aan de kleuters.\n\nTweede alinea.",
			'status'        => 'published',
			'public'        => true,
			'portal'        => 'wilgenboom',
			'audienceLabel' => 'hele school',
			'target'        => ['schoolRef' => 'school-1'],
			'publishedAt'   => '2026-10-02T09:00:00+00:00',
			'authorRef'     => 'staff-1',
			'readReceipts'  => [['subjectRef' => 'guardian-1']],
		];
	}//end row()

	public function testOnlyPublicPublishedItemsOfThisPortalAreListed(): void {
		$reader = $this->reader([
			$this->row(),
			$this->row(['id' => 'draft', 'status' => 'draft']),
			$this->row(['id' => 'private', 'public' => false]),
			$this->row(['id' => 'other', 'portal' => 'vaartveld']),
			$this->row(['id' => 'string-true', 'public' => 'true']),
		]);
		$items = $reader->listFor('wilgenboom', 12);

		$ids = array_column($items, 'id');
		$this->assertContains('n1', $ids);
		$this->assertNotContains('draft', $ids);
		$this->assertNotContains('private', $ids);
		$this->assertNotContains('other', $ids);
		$this->assertNotContains('string-true', $ids, 'only a real boolean true puts an item on the website');
	}//end testOnlyPublicPublishedItemsOfThisPortalAreListed()

	public function testAnItemFromBeforeTheFlagIsNotPublic(): void {
		$old = $this->row();
		unset($old['public'], $old['portal']);

		$this->assertSame([], $this->reader([$old])->listFor('wilgenboom', 12));
		$this->assertFalse(PublicNewsReader::isPublicOn($old, 'wilgenboom'));
	}//end testAnItemFromBeforeTheFlagIsNotPublic()

	public function testAnItemAboutSingleChildrenNeverShowsPublicly(): void {
		$reader = $this->reader([$this->row(['target' => ['groupRefs' => ['g7'], 'childRefs' => ['child-1']]])]);

		$this->assertSame([], $reader->listFor('wilgenboom', 12));
		$this->assertNull($reader->itemFor('wilgenboom', 'n1'));
	}//end testAnItemAboutSingleChildrenNeverShowsPublicly()

	public function testAVisitorGetsNoTargetAuthorOrReceipts(): void {
		$item = $this->reader([$this->row()])->listFor('wilgenboom', 4)[0];

		$this->assertSame(['id', 'title', 'intro', 'publishedAt', 'audienceLabel', 'image'], array_keys($item));
		$this->assertSame('Groep 8 las voor aan de kleuters.', $item['intro']);
		$this->assertSame('hele school', $item['audienceLabel']);
	}//end testAVisitorGetsNoTargetAuthorOrReceipts()

	public function testOnlyAPhotoFromThisPortalsPublishedLibraryIsShown(): void {
		$outside = $this->reader([$this->row(['photoRefs' => ['https://elsewhere.example/child.jpg', 'media:unknown']])]);
		$this->assertNull($outside->listFor('wilgenboom', 1)[0]['image']);

		$own = $this->reader([$this->row(['photoRefs' => ['https://elsewhere.example/child.jpg', 'media:m1']])]);
		$this->assertSame(
			['url' => 'https://school.example/media/m1?portal=wilgenboom', 'alt' => 'Kinderen lezen voor'],
			$own->listFor('wilgenboom', 1)[0]['image']
		);
	}//end testOnlyAPhotoFromThisPortalsPublishedLibraryIsShown()

	public function testNewestFirstAndTheLimitIsClamped(): void {
		$rows = [];
		for ($day = 1; $day <= 15; $day++) {
			$rows[] = $this->row(['id' => 'n'.$day, 'publishedAt' => sprintf('2026-09-%02dT09:00:00+00:00', $day)]);
		}

		$reader = $this->reader($rows);
		$this->assertSame(['n15', 'n14'], array_column($reader->listFor('wilgenboom', 2), 'id'));
		$this->assertCount(PublicNewsReader::MAX_LIMIT, $reader->listFor('wilgenboom', 99));
		$this->assertCount(1, $reader->listFor('wilgenboom', 0));
	}//end testNewestFirstAndTheLimitIsClamped()

	public function testOneItemCarriesItsBodyAndAnotherPortalGetsNothing(): void {
		$reader = $this->reader([$this->row()]);

		$item = $reader->itemFor('wilgenboom', 'n1');
		$this->assertNotNull($item);
		$this->assertStringContainsString('Tweede alinea.', $item['body']);
		$this->assertNull($reader->itemFor('vaartveld', 'n1'));
		$this->assertNull($reader->itemFor('wilgenboom', ''));
	}//end testOneItemCarriesItsBodyAndAnotherPortalGetsNothing()

	public function testTheIntroIsPlainTextCutOnAWord(): void {
		$this->assertSame('Lees de link en dit', PublicNewsReader::introOf("## Lees **de** [link](https://x.example) en dit\n\nmeer"));

		$long = str_repeat('woord ', 80);
		$intro = PublicNewsReader::introOf($long);
		$this->assertLessThanOrEqual(281, mb_strlen($intro));
		$this->assertStringEndsWith('woord…', $intro);
	}//end testTheIntroIsPlainTextCutOnAWord()
}//end class
