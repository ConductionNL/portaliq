<?php

/**
 * PublicDetailShape: what an app's public detail answer may carry to an
 * anonymous visitor, part by part.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
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

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PublicDetailShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The public detail shape.
 */
class PublicDetailShapeTest extends TestCase {
	/**
	 * The shape of an answer.
	 *
	 * @param mixed $answer The app's answer.
	 *
	 * @return array<string, mixed>|null
	 */
	private function detail(mixed $answer): ?array {
		return (new PublicDetailShape())->detail(answer: $answer);
	}//end detail()

	/**
	 * An answer that is not an object is no detail; an empty one has empty parts.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerHasEmptyParts(): void {
		$this->assertNull(actual: $this->detail(answer: 'detail'));
		$this->assertNull(actual: $this->detail(answer: null));
		$this->assertSame(expected: ['facts' => [], 'sections' => [], 'dates' => [], 'documents' => []], actual: $this->detail(answer: []));
	}//end testAnEmptyAnswerHasEmptyParts()

	/**
	 * Short texts lose their markup, collapse their whitespace and accept integers.
	 *
	 * @return void
	 */
	public function testShortTextsAreCleaned(): void {
		$detail = $this->detail(
			answer: [
				'title' => "  <b>Paspoort</b>\n aanvragen ",
				'facts' => [['label' => 'Kosten', 'value' => 75], ['label' => '<i></i>', 'value' => 'x'], ['label' => 'Duur'], 'junk'],
			]
		);
		$this->assertSame(expected: 'Paspoort aanvragen', actual: $detail['title']);
		$this->assertSame(expected: [['label' => 'Kosten', 'value' => '75']], actual: $detail['facts']);
		$this->assertSame(expected: 200, actual: mb_strlen($this->detail(answer: ['title' => str_repeat('ä', 300)])['title']));
		$this->assertArrayNotHasKey(key: 'title', array: $this->detail(answer: ['title' => ['Paspoort']]));
	}//end testShortTextsAreCleaned()

	/**
	 * A section needs a heading and markdown, which is trimmed and cut to 8000 characters.
	 *
	 * @return void
	 */
	public function testSectionsNeedAHeadingAndMarkdown(): void {
		$detail = $this->detail(
			answer: [
				'sections' => [
					['heading' => 'Wat', 'markdown' => "  **Vet**\n\n- punt  "],
					['heading' => 'Leeg', 'markdown' => '   '],
					['markdown' => 'Geen kop'],
					['heading' => 'Lang', 'markdown' => str_repeat('m', 9000)],
				],
			]
		);
		$this->assertSame(expected: ['heading' => 'Wat', 'markdown' => "**Vet**\n\n- punt"], actual: $detail['sections'][0]);
		$this->assertCount(expectedCount: 2, haystack: $detail['sections']);
		$this->assertSame(expected: 8000, actual: mb_strlen($detail['sections'][1]['markdown']));
	}//end testSectionsNeedAHeadingAndMarkdown()

	/**
	 * Dates need an id and a calendar day; the rest comes along when well formed.
	 *
	 * @return void
	 */
	public function testDatesNeedAnIdAndADay(): void {
		$detail = $this->detail(
			answer: [
				'dates' => [
					['id' => 'd1', 'date' => '2026-12-01', 'endDate' => '2026-12-02', 'label' => ' Ochtend ', 'placesLine' => 'Nog 3 plekken', 'places' => 3],
					['id' => 'd2', 'date' => '2026-12-05', 'endDate' => '5 december', 'places' => -1],
					['id' => 'd3', 'date' => '01-12-2026'],
					['id' => 'd4', 'date' => '2026-12-01T10:00:00'],
					['date' => '2026-12-01'],
					['id' => 'd5', 'date' => '2026-12-07', 'places' => 0],
				],
			]
		);
		$this->assertSame(
			expected: [
				['id' => 'd1', 'date' => '2026-12-01', 'endDate' => '2026-12-02', 'label' => 'Ochtend', 'placesLine' => 'Nog 3 plekken', 'places' => 3],
				['id' => 'd2', 'date' => '2026-12-05'],
				['id' => 'd5', 'date' => '2026-12-07', 'places' => 0],
			],
			actual: $detail['dates']
		);
	}//end testDatesNeedAnIdAndADay()

	/**
	 * Links and whether a visitor may follow them.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function hrefs(): array {
		return [
			'a path'             => ['/documenten/a.pdf', true],
			'https'              => ['https://example.org/a.pdf', true],
			'http, any case'     => ['HTTP://example.org', true],
			'protocol relative'  => ['//evil.example/a', false],
			'javascript'         => ['javascript:alert(1)', false],
			'data'               => ['data:text/html,x', false],
			'a relative path'    => ['documenten/a.pdf', false],
			'a backslash'        => ['/\\evil.example', false],
			'no host'            => ['https:///a', false],
			'mailto'             => ['mailto:info@example.org', false],
		];
	}//end hrefs()

	/**
	 * A document link is kept only for a local path or a web address.
	 *
	 * @param string $href The declared href.
	 * @param bool $kept Whether the link survives.
	 *
	 * @return void
	 */
	#[DataProvider('hrefs')]
	public function testOnlyLocalOrWebLinksAreKept(string $href, bool $kept): void {
		$documents = $this->detail(answer: ['documents' => [['label' => 'Folder', 'href' => $href]]])['documents'];
		$this->assertSame(expected: $kept, actual: $documents !== []);
	}//end testOnlyLocalOrWebLinksAreKept()

	/**
	 * The secondary link is one link, held to the same rule.
	 *
	 * @return void
	 */
	public function testTheSecondaryLinkIsOneLink(): void {
		$this->assertSame(
			expected: ['label' => 'Meer', 'href' => '/meer'],
			actual: $this->detail(answer: ['secondaryLink' => ['label' => 'Meer', 'href' => '/meer']])['secondaryLink']
		);
		$this->assertArrayNotHasKey(key: 'secondaryLink', array: $this->detail(answer: ['secondaryLink' => ['label' => 'Meer', 'href' => 'javascript:x']]));
		$this->assertArrayNotHasKey(key: 'secondaryLink', array: $this->detail(answer: ['secondaryLink' => [['label' => 'Meer', 'href' => '/meer']]]));
	}//end testTheSecondaryLinkIsOneLink()

	/**
	 * An action needs a safe id and a label; it requires sign-in unless it says false.
	 *
	 * @return void
	 */
	public function testAnActionNeedsASafeIdAndALabel(): void {
		$this->assertSame(
			expected: ['id' => 'apply', 'label' => 'Vraag aan', 'requiresSignIn' => true],
			actual: $this->detail(answer: ['action' => ['id' => 'apply', 'label' => 'Vraag aan']])['action']
		);
		$open = $this->detail(answer: ['action' => ['id' => 'a', 'label' => 'L', 'requiresSignIn' => false]])['action'];
		$this->assertFalse(condition: $open['requiresSignIn']);
		$loose = $this->detail(answer: ['action' => ['id' => 'a', 'label' => 'L', 'requiresSignIn' => 'false']])['action'];
		$this->assertTrue(condition: $loose['requiresSignIn']);
		foreach (['1apply', 'ap ply', '', str_repeat('a', 65), '<b>a</b>x!'] as $id) {
			$this->assertArrayNotHasKey(key: 'action', array: $this->detail(answer: ['action' => ['id' => $id, 'label' => 'L']]), message: $id);
		}

		$this->assertArrayNotHasKey(key: 'action', array: $this->detail(answer: ['action' => ['id' => 'apply']]));
	}//end testAnActionNeedsASafeIdAndALabel()

	/**
	 * An action with a count label takes a count of 1 to 100, 20 otherwise.
	 *
	 * @return void
	 */
	public function testTheActionCountIsBounded(): void {
		$action = ['id' => 'book', 'label' => 'Boek'];
		$counts = [[5, 5], [100, 100], [0, 20], [101, 20], ['5', 20], [null, 20]];
		foreach ($counts as [$declared, $expected]) {
			$kept = $this->detail(answer: ['action' => $action + ['countLabel' => 'Personen', 'countMax' => $declared]])['action'];
			$this->assertSame(expected: ['countLabel' => 'Personen', 'countMax' => $expected], actual: array_slice($kept, 3));
		}

		$this->assertSame(expected: 3, actual: count($this->detail(answer: ['action' => $action + ['countMax' => 5]])['action']));
	}//end testTheActionCountIsBounded()

	/**
	 * Each list is read for at most fifty rows.
	 *
	 * @return void
	 */
	public function testListsAreCappedAtFiftyRows(): void {
		$facts = array_map(static fn (int $n): array => ['label' => 'L'.$n, 'value' => 'v'], range(1, 60));
		$kept  = $this->detail(answer: ['facts' => $facts])['facts'];
		$this->assertCount(expectedCount: 50, haystack: $kept);
		$this->assertSame(expected: 'L50', actual: $kept[49]['label']);
	}//end testListsAreCappedAtFiftyRows()
}//end class
