<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalRegionResolver;
use PHPUnit\Framework\TestCase;

/**
 * portal-theme-blocks-and-contributed-pages REQ-PTB-008 and REQ-PTB-009: a
 * widget's slot selects one of five regions, `body` means `main`, a misspelt
 * slot is reported, and a present empty region survives as a key.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-widgets-slot-must-select-one-of-five-regions-req-ptb-008
 */
class PortalRegionResolverTest extends TestCase {

	public function testExistingPagesNeedNoMigration(): void {
		$resolver = new PortalRegionResolver();
		$widgets  = [
			['id' => 'a', 'slot' => 'body'],
			['id' => 'b', 'slot' => ''],
			['id' => 'c'],
		];

		$grouped = $resolver->group(widgets: $widgets);

		$this->assertSame(['main' => $widgets], $grouped['regions']);
		$this->assertSame([], $grouped['unknownRegions']);
	}//end testExistingPagesNeedNoMigration()

	public function testAMisspeltRegionIsReported(): void {
		$grouped = (new PortalRegionResolver())->group(widgets: [
			['id' => 'x', 'slot' => 'heder'],
			['id' => 'y', 'slot' => 'heder'],
			['id' => 'h', 'slot' => 'hero'],
			['id' => 'f', 'slot' => 'footer'],
			['id' => 'm', 'slot' => 'main'],
		]);

		$this->assertSame(['heder'], $grouped['unknownRegions']);
		$this->assertSame(['hero', 'main', 'footer'], array_keys($grouped['regions']), 'known regions, in render order');
	}//end testAMisspeltRegionIsReported()

	public function testEveryRegionNameIsKnownAndNothingElse(): void {
		$resolver = new PortalRegionResolver();
		foreach (['header', 'hero', 'main', 'aside', 'footer'] as $region) {
			$this->assertSame($region, $resolver->regionFor(slot: $region));
		}

		$this->assertNull($resolver->regionFor(slot: 'sidebar'));
		$this->assertNull($resolver->regionFor(slot: ['main']));
	}//end testEveryRegionNameIsKnownAndNothingElse()

	public function testAPresentEmptyRegionIsKeptAsAKey(): void {
		$ordered = (new PortalRegionResolver())->ordered(regions: ['footer' => [], 'hero' => [['widgetKey' => 'hero'], 'junk'], 'sidebar' => [['widgetKey' => 'x']]]);

		// array_key_exists, never isset or empty(): an empty list is the only
		// way to say "this region is empty on purpose".
		$this->assertSame(['hero' => [['widgetKey' => 'hero']], 'footer' => []], $ordered);
	}//end testAPresentEmptyRegionIsKeptAsAKey()

	public function testClearedRegionsAreKnownNamesOnly(): void {
		$resolver = new PortalRegionResolver();

		$this->assertSame(['hero', 'aside'], $resolver->cleared(cleared: ['aside', 'hero', 'heder', 'hero']));
		$this->assertSame([], $resolver->cleared(cleared: 'hero'));
	}//end testClearedRegionsAreKnownNamesOnly()
}//end class
