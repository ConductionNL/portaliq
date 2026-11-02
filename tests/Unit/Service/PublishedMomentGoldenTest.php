<?php

/**
 * PublishedMomentGoldenTest
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

use OCA\Portaliq\Service\NewsFeedReader;
use OCA\Portaliq\Service\PublicNewsReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The moment the guardian feed and the public news read off a row, pinned
 * per row shape for both readers.
 *
 * @covers \OCA\Portaliq\Service\NewsFeedReader
 * @covers \OCA\Portaliq\Service\PublicNewsReader
 * @uses   \OCA\Portaliq\Service\PublishedMoment
 */
class PublishedMomentGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/published-moment.json';

	/**
	 * Every moment matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryMomentMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->rows() as $name => $row) {
			foreach ([NewsFeedReader::class, PublicNewsReader::class] as $class) {
				$reflection = new ReflectionClass(objectOrClass: $class);
				$reader     = $reflection->newInstanceWithoutConstructor();
				$actual[$name][$reflection->getShortName()] = $reflection->getMethod(name: 'momentOf')->invoke($reader, $row);
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode((string)file_get_contents(self::GOLDEN), true), actual: $actual);
	}//end testEveryMomentMatchesTheGolden()

	/**
	 * Rows by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function rows(): array {
		return [
			'empty' => [],
			'publishedAt' => [
				'publishedAt' => '2026-10-01T10:00:00Z',
				'@self' => ['published' => '2026-09-01T10:00:00Z', 'created' => '2026-08-01T10:00:00Z'],
			],
			'self published' => ['@self' => ['published' => '2026-09-01T10:00:00Z', 'created' => '2026-08-01T10:00:00Z']],
			'self created' => ['@self' => ['created' => '2026-08-01T10:00:00Z']],
			'empty publishedAt' => ['publishedAt' => '', '@self' => ['created' => '2026-08-01T10:00:00Z']],
			'unreadable publishedAt' => ['publishedAt' => 'not a date', '@self' => ['published' => '2026-09-01T10:00:00Z']],
			'numeric publishedAt' => ['publishedAt' => 1767225600, '@self' => ['created' => '2026-08-01T10:00:00Z']],
			'self not an array' => ['@self' => '2026-09-01T10:00:00Z', 'publishedAt' => null],
			'all unreadable' => ['publishedAt' => 'not a date', '@self' => ['published' => 'nog niet', 'created' => 'onbekend']],
		];
	}//end rows()
}//end class
