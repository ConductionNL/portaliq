<?php

/**
 * PortalBySlugGoldenTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
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

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\Cms\AccessibilityMeasurements;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSender;
use OCA\Portaliq\Service\PortalCaseTypeCatalogue;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCA\Portaliq\Service\Signin\PortalSigninSettings;
use OCA\Portaliq\Service\Theme\PortalThemeChoice;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every place that finds one portal by its slug answers the same: what it
 * returns and what it asks the reader, per row set.
 *
 * @covers \OCA\Portaliq\Service\Cms\AccessibilityMeasurements
 * @covers \OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSender
 * @covers \OCA\Portaliq\Service\PortalCaseTypeCatalogue
 * @covers \OCA\Portaliq\Service\Signin\PortalSigninSettings
 * @covers \OCA\Portaliq\Service\Theme\PortalThemeChoice
 * @uses   \OCA\Portaliq\Service\PortalSlugLookup
 */
class PortalBySlugGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/portal-by-slug.json';

	/**
	 * The finders, as class and method.
	 */
	private const FINDERS = [
		[AccessibilityMeasurements::class, 'portalBySlug'],
		[EmailLinkSender::class, 'portal'],
		[PortalCaseTypeCatalogue::class, 'portalBySlug'],
		[PortalSigninSettings::class, 'portalBySlug'],
		[PortalThemeChoice::class, 'portalBySlug'],
	];

	public function testEveryPortalFinderMatchesTheGolden(): void {
		$actual = [];
		foreach (self::FINDERS as [$class, $method]) {
			foreach ($this->cases() as $caseName => [$slug, $rows]) {
				$asked  = [];
				$finder = $this->finder(class: $class, rows: $rows, asked: $asked);
				$result = (new ReflectionMethod($class, $method))->invoke($finder, $slug);
				$actual[$class . '::' . $method . ' | ' . $caseName] = ['result' => $result, 'asked' => $asked];
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}

	/**
	 * The slug asked for and the rows the reader answers, by case name.
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>}>
	 */
	private function cases(): array {
		$zuid  = ['slug' => 'zuid', 'title' => 'Zuid', 'status' => 'draft'];
		$noord = ['slug' => 'noord', 'title' => 'Noord'];

		return [
			'empty slug' => ['', [$zuid]],
			'match' => ['zuid', [$zuid]],
			'no rows' => ['zuid', []],
			'other slug only' => ['zuid', [$noord]],
			'match after other' => ['zuid', [$noord, $zuid]],
			'not an array first' => ['zuid', ['zuid', $zuid]],
			'array-like object first' => ['zuid', [new \ArrayObject(['slug' => 'zuid', 'title' => 'Object']), $zuid]],
			'first of two matches' => ['zuid', [$zuid, ['slug' => 'zuid', 'title' => 'Second']]],
			'slug of another type' => ['1', [['slug' => 1]]],
			'slug with a space' => ['zuid ', [$zuid]],
		];
	}

	/**
	 * A finder over a reader that answers `$rows` and records what it was asked.
	 *
	 * @param class-string            $class The finder class.
	 * @param array<int, mixed>       $rows  The rows the reader answers.
	 * @param array<int, array<string, mixed>> $asked The questions, filled in.
	 *
	 * @return object
	 */
	private function finder(string $class, array $rows, array &$asked): object {
		$reader = $this->createMock(ScopedCollectionReaderInterface::class);
		$reader->method('readCollection')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []) use ($rows, &$asked): array {
				$asked[] = compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'limit', 'scopeClaim', 'contributingApp', 'via', 'audience', 'fields', 'filter');

				return $rows;
			}
		);

		$finder = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		(function (ScopedCollectionReaderInterface $reader): void {
			$this->reader = $reader;
		})->call($finder, $reader);

		return $finder;
	}
}
