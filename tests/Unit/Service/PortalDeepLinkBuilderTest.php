<?php

/**
 * PortalDeepLinkBuilder tests (WOO-570).
 *
 * The portal deep link in out-of-band mail MUST come from the route table.
 * `getAbsoluteURL('/portal')` produced `https://host/portal` — a path no
 * deployment serves — so the only call-to-action in every task/notification
 * mail was a 404. These tests pin the route name, the tenant parameter and
 * the encoding on both kinds of instance (with and without pretty URLs).
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

use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\PortalDeepLinkBuilder
 */
final class PortalDeepLinkBuilderTest extends TestCase {
	/**
	 * Build a route-table double answering `$path` for the portal route.
	 *
	 * @param array<string, string> $expectedParameters What linkToRoute must receive.
	 * @param string $path What the route table answers.
	 *
	 * @return PortalDeepLinkBuilder
	 */
	private function builder(array $expectedParameters, string $path): PortalDeepLinkBuilder {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects($this->once())
			->method('linkToRoute')
			->with('portaliq.portalPage.index', $expectedParameters)
			->willReturn($path);
		$urlGenerator->expects($this->once())
			->method('getAbsoluteURL')
			->with($path)
			->willReturn('https://portal.example.test' . $path);

		return new PortalDeepLinkBuilder($urlGenerator);
	}//end builder()

	public function testTheLinkIsTheAppRouteWithIndexPhpOnAnInstanceWithoutPrettyUrls(): void {
		$builder = $this->builder(['org' => 'dev-org'], '/index.php/apps/portaliq/portal?org=dev-org');

		self::assertSame(
			'https://portal.example.test/index.php/apps/portaliq/portal?org=dev-org',
			$builder->forOrganisation('dev-org')
		);
	}//end testTheLinkIsTheAppRouteWithIndexPhpOnAnInstanceWithoutPrettyUrls()

	public function testTheLinkFollowsTheRouteTableOnAPrettyUrlInstance(): void {
		$builder = $this->builder(['org' => 'gemeente-x'], '/apps/portaliq/portal?org=gemeente-x');

		self::assertSame(
			'https://portal.example.test/apps/portaliq/portal?org=gemeente-x',
			$builder->forOrganisation('gemeente-x')
		);
	}//end testTheLinkFollowsTheRouteTableOnAPrettyUrlInstance()

	public function testAnUnknownTenantYieldsTheBarePortalWithoutAQuery(): void {
		$builder = $this->builder([], '/index.php/apps/portaliq/portal');

		self::assertSame('https://portal.example.test/index.php/apps/portaliq/portal', $builder->forOrganisation(''));
	}//end testAnUnknownTenantYieldsTheBarePortalWithoutAQuery()

	public function testTheTenantIsPassedToTheRouteTableVerbatimSoTheGeneratorEncodesIt(): void {
		// Encoding is the URL generator's job (it owns the query-string
		// rendering); the builder must hand the raw value over, not pre-encode
		// it and cause a double %25 escape.
		$builder = $this->builder(['org' => 'Gemeente Ãœ&co'], '/index.php/apps/portaliq/portal?org=Gemeente+%C3%9C%26co');

		self::assertStringEndsWith('?org=Gemeente+%C3%9C%26co', $builder->forOrganisation('Gemeente Ãœ&co'));
	}//end testTheTenantIsPassedToTheRouteTableVerbatimSoTheGeneratorEncodesIt()
}//end class
