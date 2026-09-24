<?php

/**
 * PortalResolverTest
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

use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\PortalResolver;
use PHPUnit\Framework\TestCase;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Host resolution, tested at the one method that does not need OpenRegister.
 *
 * `resolveByHost()` takes the site list as an argument precisely so this is
 * testable without booting a container — the decision it makes is the security
 * boundary, and a decision that can only be tested end-to-end tends not to be
 * tested at all.
 */
class PortalResolverTest extends TestCase {

	/**
	 * The resolver under test.
	 *
	 * @var PortalResolver
	 */
	private PortalResolver $resolver;

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

		$this->resolver = new PortalResolver(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->contextDouble()
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


	/**
	 * Wire the container to an ObjectService double returning fixed rows.
	 *
	 * @param array|null $rows The rows findAll() returns, or null to throw.
	 *
	 * @return PortalResolver The resolver, wired.
	 */
	private function resolverReturning(?array $rows): PortalResolver {
		$container = $this->createMock(ContainerInterface::class);

		if ($rows === null) {
			$container->method('get')->willThrowException(new RuntimeException('OR is down'));
		} else {
			$container->method('get')->willReturn(
				new class($rows) {

					/**
					 * The rows to return.
					 *
					 * @var array
					 */
					public array $rows;


					/**
					 * Constructor.
					 *
					 * @param array $rows The rows.
					 */
					public function __construct(array $rows) {
						$this->rows = $rows;
					}


					/**
					 * Set the register context.
					 *
					 * @param string $register The register slug.
					 *
					 * @return void
					 */
					public function setRegister(string $register): void {
					}


					/**
					 * Set the schema context.
					 *
					 * @param string $schema The schema slug.
					 *
					 * @return void
					 */
					public function setSchema(string $schema): void {
					}


					/**
					 * Return the fixed rows.
					 *
					 * @param array $config        The query config.
					 * @param bool  $_rbac         RBAC toggle.
					 * @param bool  $_multitenancy Multitenancy toggle.
					 *
					 * @return array The rows.
					 */
					public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
						return $this->rows;
					}


				}
			);
		}

		return new PortalResolver(
			$container,
			$this->createMock(LoggerInterface::class),
			$this->contextDouble()
		);
	}//end resolverReturning()


	/**
	 * A register-context helper that always applies.
	 *
	 * What the real one prevents — another app's leftover schema reference
	 * capturing this app's read — is asserted in its own test. Here it must
	 * simply not stand in the way of the host-matching assertions.
	 *
	 * @return PortalRegisterContext The double.
	 */
	private function contextDouble(): PortalRegisterContext {
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		return $context;
	}//end contextDouble()


	/**
	 * A request naming a site by slug gets that site.
	 *
	 * @return void
	 */
	public function testAnExplicitSlugResolvesThatSite(): void {
		$resolver = $this->resolverReturning($this->sites);

		$site = $resolver->resolve($this->createMock(IRequest::class), 'open-venray');

		$this->assertSame('open-venray', $site['slug']);
	}//end testAnExplicitSlugResolvesThatSite()


	/**
	 * An unknown slug resolves to nothing and does NOT fall through to the host.
	 *
	 * `?portal=typo` must not quietly serve whichever site owns the hostname the
	 * request happened to arrive on.
	 *
	 * @return void
	 */
	public function testAnUnknownSlugDoesNotFallThroughToTheHost(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn('tilburg.example');

		$this->assertNull($this->resolverReturning($this->sites)->resolve($request, 'does-not-exist'));
	}//end testAnUnknownSlugDoesNotFallThroughToTheHost()


	/**
	 * With no slug, resolution falls to the host.
	 *
	 * @return void
	 */
	public function testWithNoSlugTheHostDecides(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn('tilburg.example:8321');

		$site = $this->resolverReturning($this->sites)->resolve($request);

		// The port is stripped before matching; a host carrying one would
		// otherwise never match a stored hostname.
		$this->assertSame('open-tilburg', $site['slug']);
	}//end testWithNoSlugTheHostDecides()


	/**
	 * No published sites means nothing resolves.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoSitesResolvesNothing(): void {
		$this->assertNull(
			$this->resolverReturning([])->resolve($this->createMock(IRequest::class), 'open-tilburg')
		);
	}//end testAnInstanceWithNoSitesResolvesNothing()


	/**
	 * An OpenRegister failure fails CLOSED.
	 *
	 * Returning "some site" on an error would serve the wrong tenant silently.
	 * An empty list means every request 404s, which is visible immediately.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterFailureFailsClosed(): void {
		$resolver = $this->resolverReturning(null);

		$this->assertSame([], $resolver->allPublishedPortals());
		$this->assertNull($resolver->resolve($this->createMock(IRequest::class), 'open-tilburg'));
	}//end testAnOpenRegisterFailureFailsClosed()


	/**
	 * The collector resolves by HOST FIRST, the reverse of the content API:
	 * a page must not be able to attribute its events to a portal it merely
	 * names. Here the host owns open-tilburg and the body names open-venray;
	 * open-tilburg wins.
	 *
	 * @return void
	 */
	public function testTheCollectorPrefersTheHostOverANamedSlug(): void {
		$resolver = $this->resolverReturning($this->sites);
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn('tilburg.example');

		$site = $resolver->resolveForCollector($request, 'open-venray');

		$this->assertSame('open-tilburg', $site['slug']);
	}//end testTheCollectorPrefersTheHostOverANamedSlug()


	/**
	 * When the host resolves to nothing (an external portal posting to the
	 * platform host), the named slug is honoured; with no slug either, the
	 * batch resolves to nothing rather than to some default.
	 *
	 * @return void
	 */
	public function testTheCollectorFallsBackToTheSlugOnlyWhenTheHostResolvesNothing(): void {
		$resolver = $this->resolverReturning($this->sites);
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn('platform.example');

		$this->assertSame('open-venray', $resolver->resolveForCollector($request, 'open-venray')['slug']);
		$this->assertNull($resolver->resolveForCollector($request, 'typo'));
		$this->assertNull($resolver->resolveForCollector($request, null));
		$this->assertNull($resolver->resolveForCollector($request, ''));
	}//end testTheCollectorFallsBackToTheSlugOnlyWhenTheHostResolvesNothing()
	/**
	 * The published portals are read from OpenRegister once per request.
	 *
	 * `site()` composes its page from five helpers that each resolve the
	 * portal themselves, and before this each one re-ran a
	 * `findAll(limit: 500)` against OpenRegister on every public page load
	 * (review of #516). Counting the reads is the only way to see it: every
	 * assertion in this file passed with five reads and passes with one, so
	 * the duplication was invisible from the return values alone.
	 *
	 * @return void
	 */
	public function testThePortalsAreReadOnceForTheWholeRequest(): void {
		$reader   = $this->countingReader($this->sites);
		$resolver = $this->resolverOver($reader);

		$resolver->allPublishedPortals();
		$resolver->allPublishedPortals();
		$resolver->resolveByHost('tilburg.example', $resolver->allPublishedPortals());

		$this->assertSame(1, $reader->calls, 'OpenRegister must be read once per request');
	}//end testThePortalsAreReadOnceForTheWholeRequest()


	/**
	 * The memo returns the same portals it read.
	 *
	 * Guards the obvious way to make the counter above green: returning an
	 * empty array from the second call onwards would also read once.
	 *
	 * @return void
	 */
	public function testTheMemoReturnsThePortalsNotAnEmptyList(): void {
		$resolver = $this->resolverOver($this->countingReader($this->sites));

		$first  = $resolver->allPublishedPortals();
		$second = $resolver->allPublishedPortals();

		$this->assertSame($this->sites, $first);
		$this->assertSame($first, $second);
	}//end testTheMemoReturnsThePortalsNotAnEmptyList()


	/**
	 * A failed read is NOT memoised.
	 *
	 * Fail-closed means this read returns `[]` when OpenRegister throws. If
	 * that `[]` were cached, one unlucky read would 404 every remaining
	 * lookup in the request instead of just its own — a worse failure than
	 * the duplicate reads the memo removes.
	 *
	 * @return void
	 */
	public function testAFailedReadIsRetriedRatherThanMemoised(): void {
		$attempts  = 0;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function () use (&$attempts) {
				$attempts++;
				throw new RuntimeException('OR is down');
			}
		);

		$resolver = new PortalResolver(
			$container,
			$this->createMock(LoggerInterface::class),
			$this->contextDouble()
		);

		$this->assertSame([], $resolver->allPublishedPortals());
		$this->assertSame([], $resolver->allPublishedPortals());
		$this->assertSame(2, $attempts, 'a fail-closed read must be retried, not cached');
	}//end testAFailedReadIsRetriedRatherThanMemoised()


	/**
	 * An ObjectService double that counts how often it is read.
	 *
	 * @param array $rows The rows to return.
	 *
	 * @return object The counting double, carrying a public `calls` counter.
	 */
	private function countingReader(array $rows): object {
		return new class($rows) {

			/**
			 * The rows to return.
			 *
			 * @var array
			 */
			public array $rows;

			/**
			 * How often findAll() was called.
			 *
			 * @var int
			 */
			public int $calls = 0;


			/**
			 * Constructor.
			 *
			 * @param array $rows The rows.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}


			/**
			 * Set the register context.
			 *
			 * @param string $register The register slug.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
			}


			/**
			 * Set the schema context.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
			}


			/**
			 * Return the fixed rows and count the read.
			 *
			 * @param array $config        The query config.
			 * @param bool  $_rbac         RBAC toggle.
			 * @param bool  $_multitenancy Multitenancy toggle.
			 *
			 * @return array The rows.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls++;

				return $this->rows;
			}


		};
	}//end countingReader()


	/**
	 * A resolver reading through the given ObjectService double.
	 *
	 * @param object $reader The ObjectService double.
	 *
	 * @return PortalResolver The resolver.
	 */
	private function resolverOver(object $reader): PortalResolver {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($reader);

		return new PortalResolver(
			$container,
			$this->createMock(LoggerInterface::class),
			$this->contextDouble()
		);
	}//end resolverOver()


}//end class
