<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainPageRenderer;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Tests\Unit\Controller\PlainRendererFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The plain page's grid: text widgets as text, every other widget one
 * sentence that it needs JavaScript (site-honest-without-javascript REQ-SHJ-002).
 *
 * @covers \OCA\Portaliq\Service\Cms\PlainPageRenderer
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainPageRendererTest extends TestCase {

	/**
	 * @return void
	 */
	public function testTextWidgetsRender(): void {
		$view = $this->render(
			[
				['widgetKey' => 'nlHeading', 'props' => ['text' => 'Afval <b>scheiden</b>', 'level' => 9]],
				['widgetKey' => 'nlParagraph', 'props' => ['text' => "Regel een\nregel twee"]],
				['widgetKey' => 'nlList', 'props' => ['items' => ['Groente', ['title' => 'Papier', 'text' => 'op maandag'], ''], 'display' => 'numbered']],
				['widgetKey' => 'nlLinkList', 'props' => ['heading' => 'Meer', 'links' => [['label' => 'Contact', 'href' => '/contact'], ['label' => 'Rijk', 'href' => 'https://rijksoverheid.nl'], ['label' => 'Kwaad', 'href' => 'javascript:alert(1)']]]],
				['widgetKey' => 'markdown', 'props' => ['markdown' => '**Let op**']],
			]
		);

		$this->assertSame(200, $view['status']);
		$this->assertSame(
			[
				'<h6>Afval &lt;b&gt;scheiden&lt;/b&gt;</h6>',
				"<p>Regel een<br>\nregel twee</p>",
				'<ol><li>Groente</li><li>Papier op maandag</li></ol>',
				'<h2>Meer</h2><ul><li><a href="/plain?route=%2Fcontact">Contact</a></li><li><a href="https://rijksoverheid.nl">Rijk</a></li></ul>',
				'<p><strong>Let op</strong></p>',
			],
			array_column($view['blocks'], 'html')
		);
	}//end testTextWidgetsRender()

	/**
	 * @return void
	 */
	public function testAnInteractiveWidgetSaysItNeedsJavascript(): void {
		$view = $this->render([['widgetKey' => 'intakeForm', 'props' => ['form' => 'x']]]);

		$this->assertSame(
			[
				'kind'     => 'needsJs',
				'text'     => 'Aanvraagformulier: this part only works with JavaScript.',
				'href'     => '/site?route=%2Faanvragen',
				'linkText' => 'Open the full page',
			],
			$view['blocks'][0]
		);
	}//end testAnInteractiveWidgetSaysItNeedsJavascript()

	/**
	 * @return void
	 */
	public function testNoWidgetPlaceIsLeftEmpty(): void {
		$widgets = [];
		foreach (array_keys(\OCA\Portaliq\Service\Cms\PlainVocabulary::WIDGET_LABELS) as $key) {
			$widgets[] = ['widgetKey' => $key, 'props' => ['endpoint' => 'https://elders.example/x']];
		}

		$widgets[] = ['widgetKey' => 'somethingNew', 'props' => []];
		$view      = $this->render($widgets);

		$this->assertCount(count($widgets), $view['blocks']);
		foreach ($view['blocks'] as $index => $block) {
			$filled = match ($block['kind']) {
				'html' => true,
				'needsJs' => str_contains($block['text'], 'JavaScript') && $block['href'] !== '',
				default => isset($block['unavailable']['text']),
			};
			$this->assertTrue($filled, $widgets[$index]['widgetKey']);
		}
	}//end testNoWidgetPlaceIsLeftEmpty()

	/**
	 * The plain page of `/aanvragen` holding the given widgets.
	 *
	 * @param list<array<string, mixed>> $widgets The grid.
	 *
	 * @return array<string, mixed>
	 */
	private function render(array $widgets): array {
		$reader = $this->getMockBuilder(CmsReader::class)->disableOriginalConstructor()->onlyMethods(['page', 'menus'])->getMock();
		$reader->method('page')->willReturnCallback(
			static fn (string $portal, string $route, string $locale, string $audience) => ($route === '/aanvragen' && $audience === PlainPageRenderer::AUDIENCE)
				? ['title' => 'Aanvragen', 'summary' => '', 'body' => ['type' => 'grid', 'widgets' => $widgets]]
				: null
		);
		$reader->method('menus')->willReturn([]);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $name, array $params = []): string => ($name === 'portaliq.portalPage.plain' ? '/plain' : '/site').($params === [] ? '' : '?'.http_build_query($params))
		);

		return PlainRendererFactory::make(reader: $reader, urlGenerator: $urls)
			->render(portal: ['slug' => 'wilgenboom', 'title' => 'Wilgenboom'], route: '/aanvragen', locale: 'nl', portalParam: '', query: '', page: 1);
	}//end render()
}//end class
