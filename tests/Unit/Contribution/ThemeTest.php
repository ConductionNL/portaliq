<?php

/**
 * Portaliq Theme Test
 *
 * @category Test
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
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Contribution\ThemeChoice;
use PHPUnit\Framework\TestCase;

/**
 * life-domain-theme-pages REQ-LDT-001, REQ-LDT-002, REQ-LDT-004: what the
 * normaliser keeps and drops, and what the portal's themes decide.
 *
 * @spec openspec/changes/life-domain-theme-pages/specs/life-domain-themes/spec.md
 */
class ThemeTest extends TestCase {

	/**
	 * Normalise one contribution through the real normaliser.
	 *
	 * @param array<int, array<string, mixed>> $collections The declared collections.
	 * @param array<int, array<string, mixed>> $actions The declared actions.
	 *
	 * @return array<string, mixed> The normalised contribution.
	 */
	private function normalised(array $collections, array $actions=[]): array {
		return (new PortalManifestNormaliser())->normalise(['collections' => $collections, 'actions' => $actions]);
	}//end normalised()

	/**
	 * A theme is kept on a collection and on an action as a slug, and dropped otherwise.
	 *
	 * @return void
	 */
	public function testAThemeIsKeptAsASlugAndDroppedOtherwise(): void {
		$out = $this->normalised(
			[
				['id' => 'permits', 'schema' => 'permit', 'theme' => 'parkeren'],
				['id' => 'bad', 'schema' => 'permit', 'theme' => 'Par keren'],
				['id' => 'num', 'schema' => 'permit', 'theme' => 7],
			],
			[
				['id' => 'plate', 'type' => 'update', 'schema' => 'permit', 'theme' => 'parkeren'],
				['id' => 'other', 'type' => 'update', 'schema' => 'permit', 'theme' => '../x'],
			]
		);
		$this->assertSame('parkeren', $out['collections'][0]['theme']);
		$this->assertArrayNotHasKey('theme', $out['collections'][1]);
		$this->assertArrayNotHasKey('theme', $out['collections'][2]);
		$this->assertSame('parkeren', $out['actions'][0]['theme']);
		$this->assertArrayNotHasKey('theme', $out['actions'][1]);
	}//end testAThemeIsKeptAsASlugAndDroppedOtherwise()

	/**
	 * The product keys are kept on a products collection and dropped on any other.
	 *
	 * @return void
	 */
	public function testProductKeysAreKeptOnlyOnAProductsCollection(): void {
		$keys = [
			'titleField' => 'name', 'validFromField' => 'start', 'validUntilField' => 'end',
			'metaFields' => ['plate', 'street'], 'countLabel' => ['singular' => 'vergunning', 'plural' => 'vergunningen'],
		];
		$products = $this->normalised([['id' => 'permits', 'schema' => 'permit', 'kind' => 'products'] + $keys])['collections'][0];
		foreach ($keys as $key => $value) {
			$this->assertSame($value, $products[$key], $key);
		}

		$other = $this->normalised([['id' => 'cases', 'schema' => 'case', 'kind' => 'cases'] + $keys])['collections'][0];
		foreach (array_keys($keys) as $key) {
			$this->assertArrayNotHasKey($key, $other, $key);
		}

		$bad = $this->normalised([['id' => 'permits', 'schema' => 'permit', 'kind' => 'products', 'titleField' => 'a b', 'validUntilField' => 3, 'metaFields' => ['ok', 'no no', 7], 'countLabel' => ['singular' => 'x']]])['collections'][0];
		$this->assertArrayNotHasKey('titleField', $bad);
		$this->assertArrayNotHasKey('validUntilField', $bad);
		$this->assertSame(['ok'], $bad['metaFields']);
		$this->assertArrayNotHasKey('countLabel', $bad);
		$none = $this->normalised([['id' => 'permits', 'schema' => 'permit', 'kind' => 'products', 'metaFields' => ['no no']]])['collections'][0];
		$this->assertArrayNotHasKey('metaFields', $none);
	}//end testProductKeysAreKeptOnlyOnAProductsCollection()

	/**
	 * An action's `when` is kept when it is a field, an operator and a value that fit, and dropped otherwise.
	 *
	 * @return void
	 */
	public function testAWhenConditionIsKeptOnlyWhenWellFormed(): void {
		$when = static fn (mixed $condition): array => ['id' => 'visitor', 'type' => 'update', 'schema' => 'permit', 'when' => $condition];
		$out  = $this->normalised([], [
			$when(['field' => 'type', 'op' => 'eq', 'value' => 'bezoekers']),
			$when(['field' => 'type', 'op' => 'in', 'value' => ['a', 'b']]),
			$when(['field' => 'type', 'op' => 'in', 'value' => 'a']),
			$when(['field' => 'type', 'op' => 'like', 'value' => 'a']),
			$when(['field' => 'type op', 'op' => 'eq', 'value' => 'a']),
			$when(['field' => 'type', 'op' => 'eq']),
			$when('type=bezoekers'),
			$when(['field' => 'type', 'op' => 'eq', 'value' => 'a', 'extra' => 1]),
		])['actions'];
		$this->assertSame(['field' => 'type', 'op' => 'eq', 'value' => 'bezoekers'], $out[0]['when']);
		$this->assertSame(['a', 'b'], $out[1]['when']['value']);
		foreach ([2, 3, 4, 5, 6] as $index) {
			$this->assertArrayNotHasKey('when', $out[$index], 'action '.$index);
		}

		$this->assertSame(['field' => 'type', 'op' => 'eq', 'value' => 'a'], $out[7]['when'], 'an unknown key is not carried');
	}//end testAWhenConditionIsKeptOnlyWhenWellFormed()

	/**
	 * A tag the portal does not declare is dropped, and a declared theme with nothing tagged is not announced.
	 *
	 * @return void
	 */
	public function testOnlyDeclaredThemesWithContentAreAnnounced(): void {
		$aggregate = ['contributions' => [
			['app' => 'dossiq', 'collections' => [['id' => 'permits', 'theme' => 'parkeren'], ['id' => 'tax', 'theme' => 'belasting']], 'actions' => [['id' => 'plate', 'theme' => 'parkeren'], ['id' => 'x']]],
			['app' => 'other', 'collections' => [], 'actions' => [['id' => 'y', 'theme' => 'parkeren']]],
		]];
		$themes = [
			['slug' => 'parkeren', 'title' => 'Parkeren', 'intro' => 'Uw vergunningen.', 'productsLabel' => 'parkeervergunningen'],
			['slug' => 'inkomen', 'title' => 'Inkomen'],
			['slug' => 'Bad Slug', 'title' => 'Fout'],
			['slug' => 'zonder-titel', 'title' => ' '],
			['slug' => 'parkeren', 'title' => 'Dubbel'],
			'text',
		];
		$out = (new ThemeChoice())->arrange(aggregate: $aggregate, themes: $themes);
		$this->assertSame(
			[['slug' => 'parkeren', 'title' => 'Parkeren', 'intro' => 'Uw vergunningen.', 'productsLabel' => 'parkeervergunningen']],
			$out['themes'],
			'Inkomen has nothing tagged, so the menu does not list it'
		);
		$this->assertArrayNotHasKey('theme', $out['contributions'][0]['collections'][1], 'a tag the portal does not declare is dropped');
		$this->assertSame('parkeren', $out['contributions'][0]['collections'][0]['theme']);
		$this->assertSame('parkeren', $out['contributions'][1]['actions'][0]['theme'], 'one theme gathers two apps');
	}//end testOnlyDeclaredThemesWithContentAreAnnounced()

	/**
	 * A portal that declares no themes announces none and drops every tag.
	 *
	 * @return void
	 */
	public function testAPortalWithoutThemesAnnouncesNone(): void {
		$aggregate = ['contributions' => [['app' => 'dossiq', 'collections' => [['id' => 'permits', 'theme' => 'parkeren']], 'actions' => []]]];
		foreach ([null, [], 'x'] as $themes) {
			$out = (new ThemeChoice())->arrange(aggregate: $aggregate, themes: $themes);
			$this->assertSame([], $out['themes']);
			$this->assertArrayNotHasKey('theme', $out['contributions'][0]['collections'][0]);
		}

	}//end testAPortalWithoutThemesAnnouncesNone()
}//end class
