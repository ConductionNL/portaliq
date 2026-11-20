<?php

/**
 * PlainPageRenderer: the plain site page's view model. Text widgets render
 * their text, every other widget says it needs JavaScript, no widget place is
 * left empty, and every read is anonymous (site-honest-without-javascript
 * REQ-SHJ-002).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainAddress;
use OCA\Portaliq\Service\Cms\PlainCategoryNames;
use OCA\Portaliq\Service\Cms\PlainContext;
use OCA\Portaliq\Service\Cms\PlainDates;
use OCA\Portaliq\Service\Cms\PlainMarkdown;
use OCA\Portaliq\Service\Cms\PlainPageRenderer;
use OCA\Portaliq\Service\Cms\PlainPublicationDetail;
use OCA\Portaliq\Service\Cms\PlainPublicationReader;
use OCA\Portaliq\Service\Cms\PlainPublicationSearch;
use OCA\Portaliq\Service\Cms\PlainPublicRecords;
use OCA\Portaliq\Service\Cms\PlainQuery;
use OCA\Portaliq\Service\Cms\PlainReadWidgets;
use OCA\Portaliq\Service\Cms\PlainRecordPager;
use OCA\Portaliq\Kernel\Contracts\PublicRecordReaderInterface;
use OCA\Portaliq\Service\Cms\PlainTextBlocks;
use OCA\Portaliq\Service\Cms\PlainWidgetLabels;
use OCA\Portaliq\Service\CmsReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlainPageRenderer::class)]
#[UsesClass(PlainAddress::class)]
#[UsesClass(PlainContext::class)]
#[UsesClass(PlainMarkdown::class)]
#[UsesClass(PlainWidgetLabels::class)]
#[UsesClass(PlainTextBlocks::class)]
#[UsesClass(PlainPublicationSearch::class)]
#[UsesClass(PlainPublicationDetail::class)]
#[UsesClass(PlainDates::class)]
#[UsesClass(PlainCategoryNames::class)]
#[UsesClass(PlainPublicRecords::class)]
#[UsesClass(PlainRecordPager::class)]
#[UsesClass(PlainQuery::class)]
#[UsesClass(PlainReadWidgets::class)]
class PlainPageRendererTest extends TestCase {

	/**
	 * The portal every test serves.
	 *
	 * @var array<string, string>
	 */
	private const PORTAL = ['slug' => 'demo', 'title' => 'Gemeente Demo'];

	/**
	 * The publication reader double.
	 *
	 * @var PlainPublicationReader&MockObject
	 */
	private PlainPublicationReader $publications;

	/**
	 * Heading, paragraph, list, link list and markdown render their text in
	 * grid order; links into the site point at the plain version.
	 *
	 * @return void
	 */
	public function testTextWidgetsRender(): void {
		$page = $this->gridPage(widgets: [
			['widgetKey' => 'nlHeading', 'props' => ['text' => 'Afval', 'level' => 9]],
			['widgetKey' => 'nlParagraph', 'props' => ['text' => 'Zo werkt het.', 'lead' => true]],
			['widgetKey' => 'nlList', 'props' => ['items' => ['Groen', ['title' => 'Papier', 'text' => 'op maandag'], '', 7], 'ordered' => true]],
			['widgetKey' => 'nlLinkList', 'props' => ['heading' => 'Meer', 'links' => [
				['label' => 'Containers', 'href' => '/afval/containers?f.wooCategory=c1'],
				['label' => 'Rijksoverheid', 'href' => 'https://www.rijksoverheid.nl', 'description' => 'Landelijk'],
				['label' => 'Kwaad', 'href' => 'javascript:alert(1)'],
			]]],
			['widgetKey' => 'markdown', 'props' => ['markdown' => "## Let op\n\n<script>alert(1)</script>"]],
		]);

		$view = $this->renderer(pages: ['/afval' => $page])->render(self::PORTAL, '/afval', 'nl', ['query' => '', 'page' => 1], $this->address());

		$this->assertSame(200, $view['status']);
		$this->assertSame('Afval', $view['title']);
		$this->assertSame('Gemeente Demo', $view['portalTitle']);
		$this->assertSame([
			['kind' => 'heading', 'level' => 6, 'text' => 'Afval'],
			['kind' => 'paragraph', 'text' => 'Zo werkt het.', 'lead' => true],
			['kind' => 'list', 'ordered' => true, 'items' => [
				['title' => 'Groen', 'text' => ''],
				['title' => 'Papier', 'text' => 'op maandag'],
				['title' => '7', 'text' => ''],
			]],
			['kind' => 'links', 'heading' => 'Meer', 'intro' => '', 'links' => [
				['label' => 'Containers', 'href' => '/index.php/apps/portaliq/site/plain?route=/afval/containers&portal=demo&f.wooCategory=c1', 'description' => ''],
				['label' => 'Rijksoverheid', 'href' => 'https://www.rijksoverheid.nl', 'description' => 'Landelijk'],
			]],
		], array_slice($view['blocks'], 0, 4));
		$this->assertSame('html', $view['blocks'][4]['kind']);
		$this->assertStringContainsString('Let op</h2>', $view['blocks'][4]['html']);
		$this->assertStringContainsString('&lt;script&gt;', $view['blocks'][4]['html']);
		$this->assertStringNotContainsString('<script', $view['blocks'][4]['html']);
		$this->assertSame([
			['label' => 'Home', 'href' => '/index.php/apps/portaliq/site/plain?route=/&portal=demo', 'items' => [
				['label' => 'Contact', 'href' => '/index.php/apps/portaliq/site/plain?route=/contact&portal=demo'],
			]],
			['label' => 'Elders', 'href' => 'https://elders.example', 'items' => []],
		], $view['menu']);
	}//end testTextWidgetsRender()

	/**
	 * An intake form says it needs JavaScript, by its label, with a link to
	 * the full page that keeps the route and the search.
	 *
	 * @return void
	 */
	public function testAnInteractiveWidgetSaysItNeedsJavascript(): void {
		$page = $this->gridPage(widgets: [['widgetKey' => 'intakeForm', 'props' => ['formId' => 'f1']]]);

		$view = $this->renderer(pages: ['/aanvragen' => $page])->render(self::PORTAL, '/aanvragen', 'nl', ['query' => 'paspoort', 'page' => 1], $this->address());

		$this->assertSame([
			'kind' => 'needsJs',
			'label' => 'Aanvraagformulier',
			'sentence' => 'This part of the page, "Aanvraagformulier", needs JavaScript.',
			'fullUrl' => '/index.php/apps/portaliq/site?route=/aanvragen&portal=demo&_search=paspoort',
			'fullLabel' => 'Open the full page',
		], $view['blocks'][0]);
		$this->assertSame('/index.php/apps/portaliq/site?route=/aanvragen&portal=demo&_search=paspoort', $view['fullUrl']);
	}//end testAnInteractiveWidgetSaysItNeedsJavascript()

	/**
	 * Every placement gives exactly one block: an empty text widget, an
	 * unknown key, a detail widget without an id and a records widget
	 * without an app all say they need
	 * JavaScript rather than vanish.
	 *
	 * @return void
	 */
	public function testNoWidgetPlaceIsLeftEmpty(): void {
		$widgets = [
			['widgetKey' => 'nlHeading', 'props' => ['text' => '  ']],
			['widgetKey' => 'markdown', 'props' => []],
			['widgetKey' => 'nlLinkList', 'props' => ['links' => [['label' => 'Nergens']]]],
			['widgetKey' => 'somethingNew', 'props' => []],
			['widgetKey' => 'publicationDetail', 'props' => []],
			['widgetKey' => 'hero', 'props' => ['title' => 'Welkom']],
			['widgetKey' => 'publicRecords', 'props' => ['list' => 'zonder-app']],
			'not a widget',
		];

		$renderer = $this->renderer(pages: ['/' => $this->gridPage(widgets: $widgets)]);
		$view = $renderer->render(self::PORTAL, '/', 'nl', ['query' => '', 'page' => 1], $this->address());

		$this->assertCount(7, $view['blocks']);
		$labels = [];
		foreach ($view['blocks'] as $block) {
			$this->assertSame('needsJs', $block['kind']);
			$this->assertNotSame('', $block['sentence']);
			$labels[] = $block['label'];
		}

		$this->assertSame(['Kop', 'Tekst', 'Lijst met links', 'Something New', 'Publicatiedetail', 'Hero', 'Openbare overzichten'], $labels);
	}//end testNoWidgetPlaceIsLeftEmpty()

	/**
	 * The menu block shows the portal's main menu as plain links, children
	 * after their parent.
	 *
	 * @return void
	 */
	public function testTheMenuBlockShowsTheMainMenu(): void {
		$page = $this->gridPage(widgets: [['widgetKey' => 'siteNavigation', 'props' => ['label' => 'Menu']]]);

		$view = $this->renderer(pages: ['/' => $page])->render(self::PORTAL, '/', 'nl', ['query' => '', 'page' => 1], $this->address());

		$this->assertSame(['kind' => 'links', 'heading' => '', 'intro' => '', 'links' => [
			['label' => 'Home', 'href' => '/index.php/apps/portaliq/site/plain?route=/&portal=demo', 'description' => ''],
			['label' => 'Contact', 'href' => '/index.php/apps/portaliq/site/plain?route=/contact&portal=demo', 'description' => ''],
			['label' => 'Elders', 'href' => 'https://elders.example', 'description' => ''],
		]], $view['blocks'][0]);
	}//end testTheMenuBlockShowsTheMainMenu()

	/**
	 * A route with no page falls back one level to its parent, whose detail
	 * widget reads the trailing segment; a missing publication makes the
	 * page 404. A route two levels short of a page is not found.
	 *
	 * @return void
	 */
	public function testAPublicationRouteOpensItsParentPage(): void {
		$page = $this->gridPage(widgets: [['widgetKey' => 'publicationDetail', 'props' => []]]);
		$this->publications = $this->getMockBuilder(PlainPublicationReader::class)->disableOriginalConstructor()->onlyMethods(['publication'])->getMock();
		$this->publications->method('publication')->willReturnCallback(
			static fn (string $endpoint, string $id): array => ($id === 'p1'
				? ['state' => 'ok', 'publication' => ['id' => 'p1', 'title' => 'Woo-besluit'], 'documents' => []]
				: ['state' => 'not-found'])
		);
		$renderer = $this->renderer(pages: ['/publicatie' => $page], keepReader: true);

		$found = $renderer->render(self::PORTAL, '/publicatie/p1', 'nl', ['query' => '', 'page' => 1], $this->address());
		$missing = $renderer->render(self::PORTAL, '/publicatie/nope', 'nl', ['query' => '', 'page' => 1], $this->address());
		$deeper = $renderer->render(self::PORTAL, '/publicatie/a/b', 'nl', ['query' => '', 'page' => 1], $this->address());

		$this->assertSame(200, $found['status']);
		$this->assertSame('Woo-besluit', $found['blocks'][0]['title']);
		$this->assertSame(404, $missing['status']);
		$this->assertSame('not-found', $missing['blocks'][0]['state']);
		$this->assertSame(404, $deeper['status']);
		$this->assertSame([], $deeper['blocks']);
	}//end testAPublicationRouteOpensItsParentPage()

	/**
	 * A route without a published page, and a request without a portal,
	 * answer 404 with the not-found words; a markdown page renders its body.
	 * Every read names the anonymous audience.
	 *
	 * @return void
	 */
	public function testAMissingPageIsNotFoundAndEveryReadIsAnonymous(): void {
		$markdown = ['title' => 'Over ons', 'summary' => 'Wie wij zijn.', 'body' => ['type' => 'markdown', 'markdown' => 'Wij zijn **de gemeente**.']];
		$renderer = $this->renderer(pages: ['/over-ons' => $markdown]);

		$about = $renderer->render(self::PORTAL, 'over-ons/', 'nl', ['query' => '', 'page' => 1], $this->address());
		$draft = $renderer->render(self::PORTAL, '/concept', 'nl', ['query' => '', 'page' => 1], $this->address());
		$nobody = $renderer->render(null, '/over-ons', 'nl', ['query' => '', 'page' => 1], $this->address());

		$this->assertSame(200, $about['status']);
		$this->assertSame('Wie wij zijn.', $about['summary']);
		$this->assertSame('<p>Wij zijn <strong>de gemeente</strong>.</p>', $about['blocks'][0]['html']);
		foreach ([$draft, $nobody] as $view) {
			$this->assertSame(404, $view['status']);
			$this->assertSame('Page not found', $view['title']);
			$this->assertSame([], $view['blocks']);
		}
	}//end testAMissingPageIsNotFoundAndEveryReadIsAnonymous()

	/**
	 * A grid page.
	 *
	 * @param list<mixed> $widgets The placements, in order.
	 *
	 * @return array<string, mixed>
	 */
	private function gridPage(array $widgets): array {
		return ['title' => 'Afval', 'summary' => '', 'body' => ['type' => 'grid', 'widgets' => $widgets]];
	}//end gridPage()

	/**
	 * The addresses of a request that named the portal `demo`.
	 *
	 * @return PlainAddress
	 */
	private function address(): PlainAddress {
		return new PlainAddress('/index.php/apps/portaliq/site', 'demo');
	}//end address()

	/**
	 * The renderer over a reader that answers the given pages and one main
	 * menu, and that fails the test on any read that is not anonymous.
	 *
	 * @param array<string, array> $pages      Route to page.
	 * @param bool                 $keepReader Whether the test set its own publication reader.
	 *
	 * @return PlainPageRenderer
	 */
	private function renderer(array $pages, bool $keepReader = false): PlainPageRenderer {
		$reader = $this->getMockBuilder(CmsReader::class)->disableOriginalConstructor()->onlyMethods(['page', 'menus'])->getMock();
		$reader->method('page')->willReturnCallback(
			function (string $portal, string $route, string $locale, string $audience) use ($pages): ?array {
				$this->assertSame('anonymous', $audience);
				$this->assertSame('demo', $portal);

				return ($pages[$route] ?? null);
			}
		);
		$reader->method('menus')->willReturnCallback(
			function (string $portal, string $locale, string $audience): array {
				$this->assertSame('anonymous', $audience);

				return [
					['title' => 'Hoofdmenu', 'position' => 0, 'items' => [
						['name' => 'Home', 'link' => '/', 'items' => [['name' => 'Contact', 'link' => '/contact'], ['name' => '', 'link' => '/leeg']]],
						['name' => 'Elders', 'link' => 'https://elders.example', 'items' => []],
						['name' => 'Kwaad', 'link' => 'javascript:alert(1)', 'items' => []],
					]],
					['title' => 'Voet', 'position' => 1, 'items' => [['name' => 'Privacy', 'link' => '/privacy']]],
				];
			}
		);

		if ($keepReader === false) {
			$this->publications = $this->getMockBuilder(PlainPublicationReader::class)->disableOriginalConstructor()->onlyMethods(['search', 'publication'])->getMock();
		}

		$words = PlainTestWords::for($this);

		return new PlainPageRenderer(
			$reader,
			$words,
			new PlainMarkdown(),
			new PlainWidgetLabels(),
			new PlainTextBlocks(new PlainMarkdown()),
			new PlainReadWidgets(
				new PlainPublicationSearch($this->publications, $words, new PlainDates()),
				new PlainPublicationDetail($this->publications, $words, new PlainDates(), new PlainCategoryNames()),
				new PlainPublicRecords($this->createMock(PublicRecordReaderInterface::class), $words)
			)
		);
	}//end renderer()
}//end class
