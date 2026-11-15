<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Articles;

use OCA\Portaliq\Service\Articles\ArticleStore;
use OCA\Portaliq\Service\Articles\PageArticle;
use OCA\Portaliq\Service\Cms\MediaReferences;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The article a page names, as the site renders it.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-page-can-show-an-article
 */
#[CoversClass(PageArticle::class)]
class PageArticleTest extends TestCase {

	/**
	 * A resolver over one stored article.
	 *
	 * @param array<string, mixed>|null $article The stored article, or null.
	 * @param IURLGenerator|null        $urls    The URL generator.
	 * @param array{url: string, alt: string}|null $mediaHero What the media library answers for a hero.
	 *
	 * @return PageArticle
	 */
	private function resolver(?array $article, ?IURLGenerator $urls=null, ?array $mediaHero=null): PageArticle {
		$store = $this->createMock(ArticleStore::class);
		$store->method('find')->willReturnCallback(
			static fn (string $id): ?array => ($article !== null && ($id === ($article['id'] ?? '') || $id === ($article['slug'] ?? ''))) ? $article : null
		);
		$media = $this->createMock(MediaReferences::class);
		$media->method('markdown')->willReturnCallback(static fn (string $portal, string $markdown): string => str_replace('media:x', 'https://site/m/x', $markdown));
		$media->method('hero')->willReturn($mediaHero);

		return new PageArticle($store, $urls ?? $this->createMock(IURLGenerator::class), $media);
	}//end resolver()

	/**
	 * A published article.
	 *
	 * @param array<string, mixed> $fields Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function article(array $fields=[]): array {
		return $fields + [
			'id' => 'a1',
			'slug' => 'openregister-3-0-is-uit',
			'title' => 'OpenRegister 3.0 is uit',
			'summary' => 'Wat er verandert.',
			'body' => "## Nieuw\n\n![Schema](media:x)",
			'status' => 'published',
			'organisation' => 'org-1',
		];
	}//end article()

	public function testAPublishedArticleIsShownWithItsBodyAndAnAppImage(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('imagePath')->with('pipelinq', 'marketing/openregister-3-0.svg')->willReturn('/apps/pipelinq/img/marketing/openregister-3-0.svg');

		$shown = $this->resolver($this->article(['heroImage' => 'app:pipelinq/marketing/openregister-3-0.svg']), $urls)
			->shown(portal: 'gemeente', ref: 'openregister-3-0-is-uit', organisation: 'org-1');

		$this->assertSame(
			[
				'id' => 'a1',
				'slug' => 'openregister-3-0-is-uit',
				'title' => 'OpenRegister 3.0 is uit',
				'summary' => 'Wat er verandert.',
				'hero' => ['url' => '/apps/pipelinq/img/marketing/openregister-3-0.svg', 'alt' => 'OpenRegister 3.0 is uit'],
				'markdown' => "## Nieuw\n\n![Schema](https://site/m/x)",
			],
			$shown
		);
	}//end testAPublishedArticleIsShownWithItsBodyAndAnAppImage()

	public function testAnArchivedArticleKeepsShowingButADraftOrReviewDoesNot(): void {
		$this->assertSame('a1', $this->resolver($this->article(['status' => 'archived']))->shown(portal: 'p', ref: 'a1')['id']);
		$this->assertNull($this->resolver($this->article(['status' => 'draft']))->shown(portal: 'p', ref: 'a1'));
		$this->assertNull($this->resolver($this->article(['status' => 'review']))->shown(portal: 'p', ref: 'a1'));
	}//end testAnArchivedArticleKeepsShowingButADraftOrReviewDoesNot()

	public function testAnotherOrganisationsOrAMissingArticleIsNotShown(): void {
		$this->assertNull($this->resolver($this->article())->shown(portal: 'p', ref: 'a1', organisation: 'org-2'));
		$this->assertSame('a1', $this->resolver($this->article(['organisation' => '']))->shown(portal: 'p', ref: 'a1', organisation: 'org-2')['id'], 'an article without an owner is shown');
		$this->assertNull($this->resolver($this->article())->shown(portal: 'p', ref: 'nope'));
		$this->assertNull($this->resolver($this->article())->shown(portal: 'p', ref: '  '));
	}//end testAnotherOrganisationsOrAMissingArticleIsNotShown()

	public function testAMediaOrAddressHeroGetsTheTitleAsAlternativeText(): void {
		$shown = $this->resolver($this->article(['heroImage' => 'https://example.nl/a.jpg']), null, ['url' => 'https://example.nl/a.jpg', 'alt' => ''])
			->shown(portal: 'p', ref: 'a1');
		$this->assertSame(['url' => 'https://example.nl/a.jpg', 'alt' => 'OpenRegister 3.0 is uit'], $shown['hero']);

		$kept = $this->resolver($this->article(['heroImage' => 'media:x']), null, ['url' => '/m/x', 'alt' => 'Het stadhuis'])
			->shown(portal: 'p', ref: 'a1');
		$this->assertSame('Het stadhuis', $kept['hero']['alt']);
	}//end testAMediaOrAddressHeroGetsTheTitleAsAlternativeText()

	public function testAnAppImageThatClimbsOutOrDoesNotExistShowsNothing(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willThrowException(new RuntimeException('image not found'));

		$this->assertNull($this->resolver($this->article(['heroImage' => 'app:pipelinq/missing.svg']), $urls)->shown(portal: 'p', ref: 'a1')['hero']);

		$never = $this->createMock(IURLGenerator::class);
		$never->expects($this->never())->method('imagePath');
		$this->assertNull($this->resolver($this->article(['heroImage' => 'app:pipelinq/../../secret.svg']), $never)->shown(portal: 'p', ref: 'a1')['hero']);
	}//end testAnAppImageThatClimbsOutOrDoesNotExistShowsNothing()
}//end class
