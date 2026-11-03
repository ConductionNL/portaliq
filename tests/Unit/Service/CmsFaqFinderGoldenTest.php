<?php

/**
 * Golden test for CmsFaq::finder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CmsContentCache;
use OCA\Portaliq\Service\CmsFaq;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\CmsRows;
use PHPUnit\Framework\TestCase;

/**
 * The product finder per world (pages the reader lists, finder rows, the id
 * asked for), with the reader and row calls, against a recording made on the
 * code before the split.
 *
 * @covers \OCA\Portaliq\Service\CmsFaq
 */
class CmsFaqFinderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/cms-faq-finder.json';

	/**
	 * @return void
	 */
	public function testEveryWorldMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->worlds() as $name => $world) {
			$actual[$name] = $this->trace(world: $world);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryWorldMatchesTheGolden()

	/**
	 * Ask the finder in one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return array<string, mixed> `calls` and `finder`.
	 */
	private function trace(array $world): array {
		$log    = [];
		$reader = $this->createMock(originalClassName: CmsReader::class);
		$reader->method('pages')->willReturnCallback(
			static function (string $portal, string $locale, string $audience) use (&$log, $world): array {
				$log[] = ['reader.pages', $portal, $locale, $audience];
				return ($world['pages'] ?? []);
			}
		);
		$rows = $this->createMock(originalClassName: CmsRows::class);
		$rows->method('query')->willReturnCallback(
			static function (string $schema, array $filters) use (&$log, $world): array {
				$log[] = ['rows.query', $schema, $filters];
				return ($world['rows'] ?? []);
			}
		);

		$faq    = new CmsFaq(cache: $this->createMock(originalClassName: CmsContentCache::class), rows: $rows, reader: $reader);
		$finder = $faq->finder(portal: 'zuiddrecht', id: ($world['id'] ?? ''), locale: 'nl', audience: 'resident');

		return ['calls' => $log, 'finder' => $finder];
	}//end trace()

	/**
	 * The worlds.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function worlds(): array {
		$pages = [
			['route' => '/afval', 'title' => 'Afval', 'summary' => 'Containers en ophaaldagen', 'extra' => 'x'],
			['route' => '/parkeren', 'title' => 'Parkeren', 'summary' => 'Vergunningen'],
			['route' => '/afval', 'title' => 'Afval (nieuw)', 'summary' => 'Laatste wint'],
		];
		$full  = [
			'@self'     => ['id' => 'f-1'],
			'id'        => 'flat-1',
			'uuid'      => 'u-1',
			'status'    => 'published',
			'title'     => 'Wat heeft u nodig?',
			'intro'     => 'Beantwoord een paar vragen.',
			'products'  => ['/afval', '/onbekend', 7, '/parkeren', ['x'], '/afval'],
			'questions' => [
				['id' => 'q1', 'text' => 'Heeft u een auto?', 'help' => 'Ook een deelauto', 'excludesOnYes' => ['/afval'], 'excludesOnNo' => ['/parkeren', '', 3]],
				['id' => 'q2', 'text' => '   '],
				'geen vraag',
				['text' => 'Zonder id', 'excludesOnYes' => 'losse'],
			],
		];

		return [
			'no rows'                         => ['pages' => $pages],
			'full row, no id asked'           => ['pages' => $pages, 'rows' => [$full]],
			'full row, asked by self id'      => ['pages' => $pages, 'rows' => [$full], 'id' => 'f-1'],
			'full row, asked by flat id'      => ['pages' => $pages, 'rows' => [$full], 'id' => 'flat-1'],
			'flat id when no self'            => ['pages' => $pages, 'rows' => [array_diff_key($full, ['@self' => 1])], 'id' => 'flat-1'],
			'uuid when no self or id'         => ['pages' => $pages, 'rows' => [array_diff_key($full, ['@self' => 1, 'id' => 1])], 'id' => 'u-1'],
			'no id at all'                    => ['pages' => $pages, 'rows' => [array_diff_key($full, ['@self' => 1, 'id' => 1, 'uuid' => 1])]],
			'no id at all, asked for one'     => ['pages' => $pages, 'rows' => [array_diff_key($full, ['@self' => 1, 'id' => 1, 'uuid' => 1])], 'id' => 'x'],
			'draft skipped'                   => ['pages' => $pages, 'rows' => [['status' => 'draft'] + $full, ['@self' => ['id' => 'f-2'], 'status' => 'published', 'title' => 'Tweede']]],
			'status missing skipped'          => ['pages' => $pages, 'rows' => [array_diff_key($full, ['status' => 1])]],
			'second row asked'                => ['pages' => $pages, 'rows' => [$full, ['@self' => ['id' => 'f-2'], 'status' => 'published', 'products' => '/afval']], 'id' => 'f-2'],
			'first published wins'            => ['pages' => $pages, 'rows' => [$full, ['@self' => ['id' => 'f-2'], 'status' => 'published']]],
			'unknown id'                      => ['pages' => $pages, 'rows' => [$full], 'id' => 'nope'],
			'no pages'                        => ['rows' => [$full]],
			'numeric self id'                 => ['pages' => $pages, 'rows' => [['@self' => ['id' => 12]] + $full], 'id' => '12'],
			'self id null falls through'      => ['pages' => $pages, 'rows' => [['@self' => ['id' => null]] + $full], 'id' => 'flat-1'],
			'questions not a list'            => ['pages' => $pages, 'rows' => [['questions' => 'geen'] + $full]],
		];
	}//end worlds()
}//end class
