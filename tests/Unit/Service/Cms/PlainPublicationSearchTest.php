<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainLinks;
use OCA\Portaliq\Service\Cms\PlainPublicationBlocks;
use OCA\Portaliq\Service\Cms\PlainVocabulary;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The plain publication search on a `federatedSearch` widget
 * (site-honest-without-javascript REQ-SHJ-003, REQ-SHJ-005).
 *
 * @covers \OCA\Portaliq\Service\Cms\PlainPublicationBlocks
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
 */
class PlainPublicationSearchTest extends TestCase {

	private const ENDPOINT = '/index.php/apps/opencatalogi/api/federation/publications';

	/**
	 * @return void
	 */
	public function testTheFormSubmitsToThePlainRoute(): void {
		$fake  = new FakeOpenCatalogi('fake');
		$block = $this->blocks($fake, [self::ENDPOINT => [200, ['total' => 1, 'results' => [['name' => 'Woo-besluit afvalinzameling 2026', 'publicationDate' => '2026-03-01', '@self' => ['id' => 'p1']]]]]])
			->search(props: [], route: '/zoeken', query: 'afvalinzameling', page: 1, links: $this->links(portal: 'wilgenboom'), l10n: $fake->words(), locale: 'nl');

		$this->assertSame(
			['action' => '/site/plain', 'route' => '/zoeken', 'portal' => 'wilgenboom', 'query' => 'afvalinzameling', 'label' => 'Search publications', 'button' => 'Search'],
			$block['form']
		);
		$this->assertSame(
			[['title' => 'Woo-besluit afvalinzameling 2026', 'href' => '/site/plain?route=%2Fpublicatie%2Fp1&portal=wilgenboom', 'date' => '1 maart 2026', 'summary' => '']],
			$block['results']
		);
		$this->assertSame('1 result', $block['totalText']);
		$this->assertStringContainsString('_search=afvalinzameling', $fake->calls[0]);
	}//end testTheFormSubmitsToThePlainRoute()

	/**
	 * @return void
	 */
	public function testPageTwoOfTwentyFive(): void {
		$rows   = array_map(static fn (int $n): array => ['name' => 'Publicatie '.$n, '@self' => ['id' => 'p'.$n]], range(1, 25));
		$answer = static function (array $query) use ($rows): array {
			$size = (int)$query['_limit'];
			$page = (int)$query['_page'];
			return [200, ['total' => 25, 'results' => array_slice($rows, ($page - 1) * $size, $size)]];
		};
		$fake   = new FakeOpenCatalogi('fake');
		$block  = $this->blocks($fake, [self::ENDPOINT => $answer])
			->search(props: ['pageSize' => 10], route: '/zoeken', query: 'afval', page: 2, links: $this->links(portal: ''), l10n: $fake->words(), locale: 'nl');

		$this->assertSame(25, $block['total']);
		$this->assertSame('25 results', $block['totalText']);
		$this->assertSame(['Publicatie 11', 'Publicatie 20'], [$block['results'][0]['title'], $block['results'][9]['title']]);
		$this->assertCount(10, $block['results']);
		$this->assertSame('/site/plain?route=%2Fzoeken&_search=afval', $block['previous']['href']);
		$this->assertSame('/site/plain?route=%2Fzoeken&_search=afval&_page=3', $block['next']['href']);
		$this->assertSame('Page 2 of 3', $block['pageText']);
	}//end testPageTwoOfTwentyFive()

	/**
	 * @return void
	 */
	public function testUnavailableIsNotNothingFound(): void {
		$fake = new FakeOpenCatalogi('fake');
		foreach ([
			'server error' => [$this->blocks($fake, [self::ENDPOINT => [500, []]]), []],
			'app absent'   => [$this->blocks($fake, [self::ENDPOINT => [200, ['total' => 0, 'results' => []]]], enabled: false), []],
			'foreign'      => [$this->blocks($fake, []), ['endpoint' => 'https://elders.example/api/publications']],
		] as $case => [$blocks, $props]) {
			$block = $blocks->search(props: $props, route: '/zoeken', query: 'afval', page: 1, links: $this->links(portal: ''), l10n: $fake->words(), locale: 'nl');

			$this->assertSame('unavailable', $block['state'], $case);
			$this->assertArrayNotHasKey('total', $block, $case);
			$this->assertArrayNotHasKey('results', $block, $case);
			$this->assertSame('We cannot show the publications here right now.', $block['unavailable']['text'], $case);
			$this->assertSame('/site?route=%2Fzoeken&_search=afval', $block['unavailable']['href'], $case);
		}

		$this->assertStringNotContainsString('elders.example', implode(' ', $fake->calls));
	}//end testUnavailableIsNotNothingFound()

	/**
	 * The blocks over a fake opencatalogi.
	 *
	 * @param FakeOpenCatalogi                       $fake    The fake.
	 * @param array<string, array|callable>          $answers The answers by path.
	 * @param bool                                   $enabled Whether opencatalogi is on.
	 *
	 * @return PlainPublicationBlocks
	 */
	private function blocks(FakeOpenCatalogi $fake, array $answers, bool $enabled=true): PlainPublicationBlocks {
		return new PlainPublicationBlocks($fake->reader(answers: $answers, enabled: $enabled), new PlainVocabulary());
	}//end blocks()

	/**
	 * Links whose routes print as `/site` and `/site/plain`.
	 *
	 * @param string $portal The portal named.
	 *
	 * @return PlainLinks
	 */
	private function links(string $portal): PlainLinks {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $name, array $params = []): string => ($name === 'portaliq.portalPage.plain' ? '/site/plain' : '/site').($params === [] ? '' : '?'.http_build_query($params))
		);

		return new PlainLinks($urls, $portal);
	}//end links()
}//end class
