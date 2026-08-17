<?php

/**
 * Portaliq Portal Region Resolver Test
 *
 * The three states a region can be in, and the one a naive resolver misses.
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
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalRegionResolver;
use PHPUnit\Framework\TestCase;

/**
 * The region resolver.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
class PortalRegionResolverTest extends TestCase {

	private PortalRegionResolver $resolver;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new PortalRegionResolver();
	}//end setUp()


	/**
	 * One widget.
	 *
	 * @param string $key  The widget key.
	 * @param string $slot The region.
	 * @param int    $row  Its grid row.
	 *
	 * @return array<string, mixed> The widget.
	 */
	private function widget(string $key, string $slot = '', int $row = 0): array {
		$widget = ['widgetKey' => $key, 'gridY' => $row, 'gridX' => 0];
		if ($slot !== '') {
			$widget['slot'] = $slot;
		}

		return $widget;
	}//end widget()


	/**
	 * THE THREE STATES, per region, in one place.
	 *
	 * A resolver that treats an empty list as "unset" passes inherited and
	 * overridden and fails emptied — so all three are asserted for every
	 * region, not for one representative region.
	 *
	 * @return void
	 */
	public function testEveryRegionResolvesItsThreeStates(): void {
		$portalWidget = $this->widget(key: 'portalDefault');
		$pageWidget = $this->widget(key: 'pageOverride');

		foreach (PortalRegionResolver::REGIONS as $region) {
			$portalRegions = [$region => [$portalWidget]];

			// INHERITED — the page says nothing.
			$inherited = $this->resolver->resolve(pageRegions: [], portalRegions: $portalRegions);
			$this->assertSame(
				'portalDefault',
				$inherited[$region][0]['widgetKey'],
				"the portal default did not reach an unmentioned {$region}"
			);

			// OVERRIDDEN — the page lists its own.
			$overridden = $this->resolver->resolve(
				pageRegions: [$region => [$pageWidget]],
				portalRegions: $portalRegions
			);
			$this->assertSame(
				'pageOverride',
				$overridden[$region][0]['widgetKey'],
				"the page did not override {$region}"
			);

			// EMPTIED — the page lists the region with nothing in it.
			$emptied = $this->resolver->resolve(
				pageRegions: [$region => []],
				portalRegions: $portalRegions
			);
			$this->assertSame(
				[],
				$emptied[$region],
				"an explicitly empty {$region} fell back to the portal's — empty was read as unset"
			);
		}
	}//end testEveryRegionResolvesItsThreeStates()


	/**
	 * Every region is keyed in the result, even when nothing fills it.
	 *
	 * A renderer that has to guard each region separately misses one, and the
	 * one it misses is on the portal nobody looks at.
	 *
	 * @return void
	 */
	public function testEveryRegionIsAlwaysPresentInTheResult(): void {
		$resolved = $this->resolver->resolve(pageRegions: [], portalRegions: []);

		$this->assertSame(PortalRegionResolver::REGIONS, array_keys($resolved));
		foreach ($resolved as $widgets) {
			$this->assertSame([], $widgets);
		}
	}//end testEveryRegionIsAlwaysPresentInTheResult()


	/**
	 * THE LEGACY SLOT KEEPS WORKING, without a data migration.
	 *
	 * Nine widgets in the shipped seed carry `"slot": "body"` and nothing has
	 * ever read it. It means `main`. Getting this wrong would empty the body of
	 * every existing page the moment regions shipped.
	 *
	 * @return void
	 */
	public function testTheLegacyBodySlotMeansMain(): void {
		$grouped = $this->resolver->regionsFromWidgets(
			widgets: [$this->widget(key: 'markdown', slot: 'body')]
		);

		$this->assertSame(['main'], array_keys($grouped['regions']));
		$this->assertSame([], $grouped['unknown']);
	}//end testTheLegacyBodySlotMeansMain()


	/**
	 * A widget with NO slot at all also lands in main.
	 *
	 * @return void
	 */
	public function testAWidgetWithoutASlotLandsInMain(): void {
		$grouped = $this->resolver->regionsFromWidgets(widgets: [$this->widget(key: 'markdown')]);

		$this->assertSame(['main'], array_keys($grouped['regions']));
	}//end testAWidgetWithoutASlotLandsInMain()


	/**
	 * An unknown region is REPORTED BY NAME, not dropped silently.
	 *
	 * A widget assigned to a region that does not exist is an authoring
	 * mistake, and the author needs to see the name they typed. A widget that
	 * silently vanishes is debugged by guessing.
	 *
	 * @return void
	 */
	public function testAnUnknownRegionIsReportedByName(): void {
		$grouped = $this->resolver->regionsFromWidgets(
			widgets: [
				$this->widget(key: 'a', slot: 'sidebar'),
				$this->widget(key: 'b', slot: 'sidebar'),
				$this->widget(key: 'c', slot: 'main'),
			]
		);

		$this->assertSame(['sidebar'], $grouped['unknown'], 'the mistyped region was not named');
		$this->assertSame(['main'], array_keys($grouped['regions']));
	}//end testAnUnknownRegionIsReportedByName()


	/**
	 * A flat legacy list never produces an explicitly EMPTY region.
	 *
	 * This is the other half of the three-state rule. A page whose widget list
	 * happens to contain nothing for the header must INHERIT the portal's
	 * header, not blank it — otherwise shipping regions would strip the chrome
	 * from every existing page at once.
	 *
	 * @return void
	 */
	public function testALegacyListLeavesUnmentionedRegionsInheritable(): void {
		$grouped = $this->resolver->regionsFromWidgets(
			widgets: [$this->widget(key: 'markdown', slot: 'main')]
		);

		$this->assertArrayNotHasKey('header', $grouped['regions']);

		$resolved = $this->resolver->resolve(
			pageRegions: $grouped['regions'],
			portalRegions: ['header' => [$this->widget(key: 'brandHeader')]]
		);

		$this->assertSame('brandHeader', $resolved['header'][0]['widgetKey']);
	}//end testALegacyListLeavesUnmentionedRegionsInheritable()


	/**
	 * Widgets come back in grid order, whatever order they were stored in.
	 *
	 * @return void
	 */
	public function testWidgetsAreOrderedByRowThenColumn(): void {
		$resolved = $this->resolver->resolve(
			pageRegions: [
				'main' => [
					['widgetKey' => 'third', 'gridY' => 2, 'gridX' => 0],
					['widgetKey' => 'second', 'gridY' => 1, 'gridX' => 6],
					['widgetKey' => 'first', 'gridY' => 1, 'gridX' => 0],
				],
			],
			portalRegions: []
		);

		$this->assertSame(
			['first', 'second', 'third'],
			array_column($resolved['main'], 'widgetKey')
		);
	}//end testWidgetsAreOrderedByRowThenColumn()


}//end class
