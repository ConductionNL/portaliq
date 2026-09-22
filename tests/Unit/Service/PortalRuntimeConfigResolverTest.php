<?php

/**
 * PortalRuntimeConfigResolverTest
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

use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalRuntimeConfigResolver;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The portal object is the tenant source for `/portal` (WOO-566).
 *
 * These assertions are the executable form of a product decision taken on
 * 2026-09-22: an organisation may run several portals that look different from
 * each other. Everything below follows from that — which parameter wins, why
 * `?org=` refuses to guess, and why an unresolved request gets NOTHING rather
 * than something plausible.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
 */
class PortalRuntimeConfigResolverTest extends TestCase {


	/**
	 * The neutral default every branch starts from.
	 *
	 * @var array<string, mixed>
	 */
	private const NEUTRAL = [
		'organisationName' => 'Portaliq',
		'organisationSlug' => '',
		'theme' => 'default',
		'logo' => null,
		'featureFlags' => [],
		'allowedEmbedOrigins' => [],
		'apiBase' => '/index.php/apps/portaliq/portal/api',
		'audience' => 'supplier',
		'locale' => 'nl',
		'oidcProviders' => [],
	];


	/**
	 * A resolved portal's own name reaches the SPA header.
	 *
	 * The bug in one line: this was the literal 'Portaliq' for every tenant on
	 * every request, because the value came from a config blob nothing has
	 * ever written.
	 *
	 * @return void
	 */
	public function testAResolvedPortalSuppliesItsOwnName(): void {
		$config = $this->resolver()->runtimeConfigFor(
			portal: ['slug' => 'demo', 'title' => 'Open Catalogi'],
			orgValue: '',
			locale: 'nl'
		);

		$this->assertSame('Open Catalogi', $config['organisationName']);
		$this->assertSame('demo', $config['organisationSlug']);
	}//end testAResolvedPortalSuppliesItsOwnName()


	/**
	 * A portal with a blank title leaves the neutral name standing.
	 *
	 * An empty header is not more honest than a generic one, it is just
	 * broken.
	 *
	 * @return void
	 */
	public function testABlankTitleDoesNotBlankTheHeader(): void {
		$config = $this->resolver()->runtimeConfigFor(
			portal: ['slug' => 'demo', 'title' => '   '],
			orgValue: '',
			locale: 'nl'
		);

		$this->assertSame('Portaliq', $config['organisationName']);
	}//end testABlankTitleDoesNotBlankTheHeader()


	/**
	 * The theme is reported ONLY when a token set actually backs it.
	 *
	 * THIS IS THE SUBTLE ONE. The SPA turns this value into a `theme-<name>`
	 * class on its root element. If the value were passed through unchecked, a
	 * portal configured with a theme no token file backs would carry a
	 * perfectly plausible, brand-shaped class name on a page wearing none of
	 * that brand's colours — and anyone verifying the fix by reading the DOM
	 * would see the right answer on the wrong page. That is the exact shape of
	 * the failure that kept the original bug hidden for as long as it was.
	 *
	 * @return void
	 */
	public function testAnUnresolvableThemeIsNotReportedAsResolved(): void {
		$resolver = $this->resolver(themeStylesheet: null);

		$config = $resolver->runtimeConfigFor(
			portal: ['slug' => 'demo', 'theme' => 'a-brand-that-ships-no-tokens'],
			orgValue: '',
			locale: 'nl'
		);

		$this->assertSame('default', $config['theme']);
	}//end testAnUnresolvableThemeIsNotReportedAsResolved()


	/**
	 * A theme a token set DOES back is reported.
	 *
	 * @return void
	 */
	public function testAResolvableThemeIsReported(): void {
		$resolver = $this->resolver(themeStylesheet: 'tokens/opencatalogi');

		$config = $resolver->runtimeConfigFor(
			portal: ['slug' => 'demo', 'theme' => 'opencatalogi'],
			orgValue: '',
			locale: 'nl'
		);

		$this->assertSame('opencatalogi', $config['theme']);
		$this->assertSame('tokens/opencatalogi', $resolver->themeStylesheetFor(['theme' => 'opencatalogi']));
	}//end testAResolvableThemeIsReported()


	/**
	 * An unresolved request gets the NEUTRAL DEFAULT, whole and untouched.
	 *
	 * Specifically: no organisation branding leaks back in. The old
	 * OpenRegister Organisation lookup is gone from this path rather than
	 * kept beneath it as a fallback, because there has never been a writer for
	 * the key it read.
	 *
	 * @return void
	 */
	public function testAnUnresolvedRequestGetsTheNeutralDefault(): void {
		$config = $this->resolver()->runtimeConfigFor(portal: null, orgValue: 'gemeente-x', locale: 'nl');

		$this->assertSame('Portaliq', $config['organisationName']);
		$this->assertSame('', $config['organisationSlug']);
		$this->assertSame('default', $config['theme']);
		$this->assertNull($config['logo']);
		$this->assertSame([], $config['allowedEmbedOrigins']);
	}//end testAnUnresolvedRequestGetsTheNeutralDefault()


	/**
	 * The config shape is complete on every path.
	 *
	 * The SPA reads these keys unguarded, so a branch that returns a partial
	 * array is an undefined-key crash on a public page rather than a styling
	 * problem.
	 *
	 * @return void
	 */
	public function testEveryBranchReturnsTheCompleteShape(): void {
		$resolver = $this->resolver();

		foreach ([null, ['slug' => 'demo', 'title' => 'Open Catalogi']] as $portal) {
			$config = $resolver->runtimeConfigFor(portal: $portal, orgValue: '', locale: 'nl');
			foreach (array_keys(self::NEUTRAL) as $key) {
				$this->assertArrayHasKey($key, $config);
			}
		}
	}//end testEveryBranchReturnsTheCompleteShape()


	/**
	 * `frameAncestors` on the portal object feeds the embed policy.
	 *
	 * @return void
	 */
	public function testFrameAncestorsComeFromThePortal(): void {
		$config = $this->resolver()->runtimeConfigFor(
			portal: [
				'slug' => 'demo',
				'frameAncestors' => ['https://gemeente-x.example', ' https://two.example ', '', 'https://gemeente-x.example'],
			],
			orgValue: '',
			locale: 'nl'
		);

		$this->assertSame(
			['https://gemeente-x.example', 'https://two.example'],
			$config['allowedEmbedOrigins']
		);
	}//end testFrameAncestorsComeFromThePortal()


	/**
	 * A malformed `frameAncestors` stays fail-closed.
	 *
	 * The empty list is what the controller turns into `frame-ancestors
	 * 'none'`, so "we could not read it" and "embedding is not allowed" have
	 * to be the same answer.
	 *
	 * @return void
	 */
	public function testAMalformedFrameAncestorsListFailsClosed(): void {
		$resolver = $this->resolver();

		foreach ([null, 'https://x.example', 42] as $malformed) {
			$config = $resolver->runtimeConfigFor(
				portal: ['slug' => 'demo', 'frameAncestors' => $malformed],
				orgValue: '',
				locale: 'nl'
			);

			$this->assertSame([], $config['allowedEmbedOrigins']);
		}
	}//end testAMalformedFrameAncestorsListFailsClosed()


	/**
	 * The OIDC providers still come from the ORGANISATION, off `?org=`.
	 *
	 * The presentation moved to the portal object; the broker did not. Two
	 * portals of one tenant share an identity provider even when they share no
	 * colours, and the client secret belongs to the legal tenant.
	 *
	 * @return void
	 */
	public function testOidcProvidersStillComeFromTheOrganisation(): void {
		$orgResolver = $this->createMock(PortalOrganisationConfigService::class);
		$orgResolver->method('resolve')->willReturnCallback(
			static function (string $orgSlug, string $locale = 'nl'): array {
				if ($orgSlug !== 'gemeente-x') {
					return self::NEUTRAL;
				}

				return array_merge(
					self::NEUTRAL,
					[
						// A tenant the OLD path would have branded. None of
						// these presentation keys may survive into the config:
						// only `oidcProviders` is taken from this result.
						'organisationName' => 'Gemeente X',
						'theme' => 'gemeente-x-brand',
						'oidcProviders' => [['provider' => 'digid', 'label' => 'DigiD']],
					]
				);
			}
		);

		$resolver = $this->resolver(orgResolver: $orgResolver);

		$this->assertSame(
			[['provider' => 'digid', 'label' => 'DigiD']],
			$resolver->runtimeConfigFor(portal: null, orgValue: 'gemeente-x', locale: 'nl')['oidcProviders']
		);
		$this->assertSame(
			[],
			$resolver->runtimeConfigFor(portal: null, orgValue: '', locale: 'nl')['oidcProviders']
		);

		// AND THE ORGANISATION'S BRANDING STAYS OUT. This is the deleted
		// fallback layer asserted from the outside: the organisation record
		// still carries a name and a theme, the service still returns them,
		// and the portal config must show neither.
		$config = $resolver->runtimeConfigFor(portal: null, orgValue: 'gemeente-x', locale: 'nl');
		$this->assertSame('Portaliq', $config['organisationName']);
		$this->assertSame('default', $config['theme']);
	}//end testOidcProvidersStillComeFromTheOrganisation()


	/**
	 * `?portal=` is consulted first and NEVER falls through to `?org=`.
	 *
	 * A named portal that does not exist is a miss. Falling through would mean
	 * `?portal=typo&org=gemeente-x` serves gemeente-x — naming one tenant and
	 * being handed another.
	 *
	 * @return void
	 */
	public function testAnExplicitPortalSlugNeverFallsThroughToOrg(): void {
		$portalResolver = $this->createMock(PortalResolver::class);
		$portalResolver->method('resolve')->willReturn(null);
		$portalResolver->expects($this->never())->method('resolveByOrganisation');

		$resolved = $this->resolver(portalResolver: $portalResolver)->resolvePortal(
			request: $this->createMock(IRequest::class),
			portalSlug: 'typo',
			orgValue: 'gemeente-x'
		);

		$this->assertNull($resolved);
	}//end testAnExplicitPortalSlugNeverFallsThroughToOrg()


	/**
	 * `?org=` is used only when no `?portal=` was named.
	 *
	 * @return void
	 */
	public function testTheOrgAliasIsUsedWhenNoPortalIsNamed(): void {
		$portalResolver = $this->createMock(PortalResolver::class);
		$portalResolver->method('resolveByOrganisation')->willReturn(['slug' => 'testgemeente']);

		$resolved = $this->resolver(portalResolver: $portalResolver)->resolvePortal(
			request: $this->createMock(IRequest::class),
			portalSlug: '',
			orgValue: 'dev-org'
		);

		$this->assertSame('testgemeente', $resolved['slug']);
	}//end testTheOrgAliasIsUsedWhenNoPortalIsNamed()


	/**
	 * With neither parameter, the verified host decides.
	 *
	 * @return void
	 */
	public function testWithNoParametersTheHostDecides(): void {
		$portalResolver = $this->createMock(PortalResolver::class);
		$portalResolver->method('resolve')->willReturn(['slug' => 'by-host']);

		$resolved = $this->resolver(portalResolver: $portalResolver)->resolvePortal(
			request: $this->createMock(IRequest::class),
			portalSlug: '',
			orgValue: ''
		);

		$this->assertSame('by-host', $resolved['slug']);
	}//end testWithNoParametersTheHostDecides()


	/**
	 * A resolver that throws fails closed to no portal.
	 *
	 * An OpenRegister that is down renders the neutral shell. The alternative
	 * — a 500 — turns one unlucky read into an outage on the one genuinely
	 * public page in the fleet.
	 *
	 * @return void
	 */
	public function testAThrowingResolverFailsClosed(): void {
		$portalResolver = $this->createMock(PortalResolver::class);
		$portalResolver->method('resolve')->willThrowException(new RuntimeException('OR is down'));

		$this->assertNull(
			$this->resolver(portalResolver: $portalResolver)->resolvePortal(
				request: $this->createMock(IRequest::class),
				portalSlug: 'demo',
				orgValue: ''
			)
		);
	}//end testAThrowingResolverFailsClosed()


	/**
	 * Build the resolver under test.
	 *
	 * @param PortalResolver|null                  $portalResolver  The portal resolver double.
	 * @param PortalOrganisationConfigService|null  $orgResolver     The organisation service double.
	 * @param string|null                          $themeStylesheet What `stylesheetFor()` answers.
	 *
	 * @return PortalRuntimeConfigResolver The resolver, wired.
	 */
	private function resolver(
		?PortalResolver $portalResolver = null,
		?PortalOrganisationConfigService $orgResolver = null,
		?string $themeStylesheet = 'tokens/opencatalogi'
	): PortalRuntimeConfigResolver {
		$themeResolver = $this->createMock(PortalThemeResolver::class);
		$themeResolver->method('stylesheetFor')->willReturn($themeStylesheet);

		return new PortalRuntimeConfigResolver(
			($portalResolver ?? $this->createMock(PortalResolver::class)),
			($orgResolver ?? $this->orgResolverDouble()),
			$themeResolver
		);
	}//end resolver()


	/**
	 * An organisation service that answers the neutral default and no
	 * providers.
	 *
	 * @return PortalOrganisationConfigService The double.
	 */
	private function orgResolverDouble(): PortalOrganisationConfigService {
		$orgResolver = $this->createMock(PortalOrganisationConfigService::class);
		$orgResolver->method('resolve')->willReturn(self::NEUTRAL);

		return $orgResolver;
	}//end orgResolverDouble()


}//end class
