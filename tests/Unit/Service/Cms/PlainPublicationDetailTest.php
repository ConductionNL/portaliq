<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PlainLinks;
use OCA\Portaliq\Service\Cms\PlainPublicationBlocks;
use OCA\Portaliq\Service\Cms\PlainPublicationReader;
use OCA\Portaliq\Service\Cms\PlainVocabulary;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The plain publication detail on a `publicationDetail` widget
 * (site-honest-without-javascript REQ-SHJ-004).
 *
 * @covers \OCA\Portaliq\Service\Cms\PlainPublicationBlocks
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-a-publication-can-be-read-without-javascript-req-shj-004
 */
class PlainPublicationDetailTest extends TestCase {

	private const ENDPOINT = '/index.php/apps/opencatalogi/api/federation/publications';

	/**
	 * @return void
	 */
	public function testThePublicationAndItsDocuments(): void {
		$fake  = new FakeOpenCatalogi('fake');
		$block = $this->blocks($fake)->detail(props: [], route: '/publicatie/p1', id: 'p1', links: $this->links(), l10n: $fake->words(), locale: 'nl');

		$this->assertSame('ok', $block['state']);
		$this->assertSame('Woo-besluit afvalinzameling 2026', $block['title']);
		$this->assertSame('Het besluit over afval.', $block['summary']);
		$this->assertSame(
			[
				['label' => 'Publication date', 'values' => ['1 maart 2026']],
				['label' => 'Information category', 'values' => ['Woo-verzoeken en -besluiten']],
				['label' => 'Themes', 'values' => ['Afval']],
			],
			$block['rows']
		);
		$this->assertSame(
			[['name' => 'besluit.pdf', 'href' => '/index.php/apps/openregister/download/1', 'facts' => 'PDF, 2,4 MB']],
			$block['documents']
		);
	}//end testThePublicationAndItsDocuments()

	/**
	 * @return void
	 */
	public function testMissingAndWithheldAnswerTheSame404(): void {
		$fake   = new FakeOpenCatalogi('fake');
		$blocks = $this->blocks($fake);

		$this->assertNull($blocks->detail(props: [], route: '/publicatie/missing', id: 'missing', links: $this->links(), l10n: $fake->words(), locale: 'nl'));
		$this->assertNull($blocks->detail(props: [], route: '/publicatie/withheld', id: 'withheld', links: $this->links(), l10n: $fake->words(), locale: 'nl'));
	}//end testMissingAndWithheldAnswerTheSame404()

	/**
	 * Blocks over opencatalogi holding `p1`, `withheld` (403) and nothing else.
	 *
	 * @param FakeOpenCatalogi $fake The fake.
	 *
	 * @return PlainPublicationBlocks
	 */
	private function blocks(FakeOpenCatalogi $fake): PlainPublicationBlocks {
		$reader = $fake->reader(
			answers: [
				self::ENDPOINT.'/p1'             => [200, ['id' => 'p1', 'name' => 'Woo-besluit afvalinzameling 2026', 'summary' => 'Het besluit over afval.', 'publicationDate' => '2026-03-01T10:00:00Z', 'wooCategory' => 'infocat014', 'themes' => ['t1']]],
				self::ENDPOINT.'/p1/attachments' => [200, ['results' => [['title' => 'besluit.pdf', 'size' => 2400000, 'downloadUrl' => '/index.php/apps/openregister/download/1']]]],
				self::ENDPOINT.'/withheld'       => [403, ['error' => 'forbidden']],
				PlainPublicationReader::THEMES_ENDPOINT.'/t1' => [200, ['title' => 'Afval']],
			]
		);

		return new PlainPublicationBlocks($reader, new PlainVocabulary());
	}//end blocks()

	/**
	 * @return PlainLinks
	 */
	private function links(): PlainLinks {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $name, array $params = []): string => '/'.$name.'?'.http_build_query($params));

		return new PlainLinks($urls, '');
	}//end links()
}//end class
