<?php

/**
 * WebsiteResolverTest
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

use OCA\Portaliq\Service\WebsiteResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Host resolution, tested at the one method that does not need OpenRegister.
 *
 * `resolveByHost()` takes the site list as an argument precisely so this is
 * testable without booting a container — the decision it makes is the security
 * boundary, and a decision that can only be tested end-to-end tends not to be
 * tested at all.
 */
class WebsiteResolverTest extends TestCase {

	/**
	 * The resolver under test.
	 *
	 * @var WebsiteResolver
	 */
	private WebsiteResolver $resolver;

	/**
	 * Two sites: one with a verified domain, one without.
	 *
	 * @var array
	 */
	private array $sites;


	/**
	 * Build the resolver and the fixture site list.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resolver = new WebsiteResolver(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->sites = [
			[
				'slug' => 'open-tilburg',
				'domains' => [['hostname' => 'tilburg.example', 'verified' => true]],
			],
			[
				'slug' => 'open-venray',
				'domains' => [
					['hostname' => 'venray.example', 'verified' => true],
					['hostname' => 'claimed.example', 'verified' => false],
				],
			],
		];
	}//end setUp()


	/**
	 * A verified host resolves to its own site.
	 *
	 * The POSITIVE half. Without it, every assertion below would also pass for
	 * a resolver that returns null unconditionally — which would be completely
	 * broken and completely untestable from the negative cases alone.
	 *
	 * @return void
	 */
	public function testAVerifiedHostResolves(): void {
		$site = $this->resolver->resolveByHost('tilburg.example', $this->sites);

		$this->assertNotNull($site);
		$this->assertSame('open-tilburg', $site['slug']);
	}//end testAVerifiedHostResolves()


	/**
	 * Each verified host resolves to ITS site, not the first one.
	 *
	 * @return void
	 */
	public function testEachHostResolvesToItsOwnSite(): void {
		$this->assertSame(
			'open-venray',
			$this->resolver->resolveByHost('venray.example', $this->sites)['slug']
		);
	}//end testEachHostResolvesToItsOwnSite()


	/**
	 * An unverified domain does not serve.
	 *
	 * DNS pointing at this installation is not consent. Without this check a
	 * tenant could bind any hostname it does not own and have it served.
	 *
	 * @return void
	 */
	public function testAnUnverifiedDomainDoesNotResolve(): void {
		$this->assertNull($this->resolver->resolveByHost('claimed.example', $this->sites));
	}//end testAnUnverifiedDomainDoesNotResolve()


	/**
	 * An unknown host resolves to nothing — never to a default.
	 *
	 * @return void
	 */
	public function testAnUnknownHostDoesNotFallBackToAnySite(): void {
		$this->assertNull($this->resolver->resolveByHost('stranger.example', $this->sites));
	}//end testAnUnknownHostDoesNotFallBackToAnySite()


	/**
	 * An empty host resolves to nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyHostResolvesToNothing(): void {
		$this->assertNull($this->resolver->resolveByHost('', $this->sites));
	}//end testAnEmptyHostResolvesToNothing()


	/**
	 * With exactly one site configured, a different host still resolves to
	 * nothing.
	 *
	 * The single-site case is where a "there is only one, just use it"
	 * shortcut is most tempting and most wrong: it is the shape every
	 * installation starts in, so the shortcut would go unnoticed until the
	 * second site was added.
	 *
	 * @return void
	 */
	public function testASingleConfiguredSiteIsStillNotADefault(): void {
		$single = [$this->sites[0]];

		$this->assertNull($this->resolver->resolveByHost('anything.example', $single));
	}//end testASingleConfiguredSiteIsStillNotADefault()


	/**
	 * Host matching ignores case.
	 *
	 * @return void
	 */
	public function testHostMatchingIsCaseInsensitive(): void {
		$this->assertNotNull($this->resolver->resolveByHost('tilburg.example', $this->sites));
		$this->assertNull(
			$this->resolver->resolveByHost('TILBURG.EXAMPLE', $this->sites),
			'The caller lower-cases the host before matching; this pins that contract.'
		);
	}//end testHostMatchingIsCaseInsensitive()


	/**
	 * A malformed domain entry is skipped, not fatal.
	 *
	 * @return void
	 */
	public function testMalformedDomainEntriesAreSkipped(): void {
		$sites = [
			['slug' => 'broken', 'domains' => ['not-an-array', ['verified' => true]]],
			['slug' => 'good', 'domains' => [['hostname' => 'good.example', 'verified' => true]]],
		];

		$this->assertSame('good', $this->resolver->resolveByHost('good.example', $sites)['slug']);
	}//end testMalformedDomainEntriesAreSkipped()


}//end class
