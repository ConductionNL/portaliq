<?php

/**
 * Tests for PortalSessionScope: a portal-bound session is admitted on its
 * own portal only.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Auth
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

namespace OCA\Portaliq\Tests\Unit\Auth;

use OCA\Portaliq\Auth\PortalSessionScope;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-session-must-be-scoped-to-the-portal-that-minted-it
 */
class PortalSessionScopeTest extends TestCase {

	/**
	 * The portal the request is served from decides, named portal passed on.
	 *
	 * @return void
	 */
	public function testOnlyTheServingPortalAdmitsItsSession(): void {
		$asked = [];
		$scope = $this->scope(serving: ['slug' => 'portal-b'], named: 'portal-a', asked: $asked);

		$this->assertFalse($scope->admits(portal: 'portal-a'));
		$this->assertTrue($scope->admits(portal: 'portal-b'));
		$this->assertSame(['portal-a', 'portal-a'], $asked);
	}//end testOnlyTheServingPortalAdmitsItsSession()

	/**
	 * A request served by no portal keeps the organisation scope; a request
	 * whose portal cannot be resolved refuses.
	 *
	 * @return void
	 */
	public function testNoServingPortalAdmitsAndAFailedResolutionRefuses(): void {
		$asked = [];
		$this->assertTrue($this->scope(serving: null, named: null, asked: $asked)->admits(portal: 'portal-a'));
		$this->assertSame([null], $asked);

		$request  = $this->createMock(IRequest::class);
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolveForCollector')->willThrowException(new RuntimeException('down'));
		$this->assertFalse((new PortalSessionScope(request: $request, portals: $resolver))->admits(portal: 'portal-a'));
	}//end testNoServingPortalAdmitsAndAFailedResolutionRefuses()

	/**
	 * A session bound to no portal is admitted without resolving anything.
	 *
	 * @return void
	 */
	public function testAnUnboundSessionIsAdmittedWithoutAsking(): void {
		$request  = $this->createMock(IRequest::class);
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->expects($this->never())->method('resolveForCollector');

		$this->assertTrue((new PortalSessionScope(request: $request, portals: $resolver))->admits(portal: ''));
	}//end testAnUnboundSessionIsAdmittedWithoutAsking()

	/**
	 * A portal the serving portal's slug field does not carry is not admitted.
	 *
	 * @return void
	 */
	public function testAServingPortalWithoutASlugAdmitsNoBoundSession(): void {
		$asked = [];
		$this->assertFalse($this->scope(serving: ['title' => 'no slug'], named: null, asked: $asked)->admits(portal: 'portal-a'));
	}//end testAServingPortalWithoutASlugAdmitsNoBoundSession()

	/**
	 * The serving portal's `minTrust` is a floor: a session below it is
	 * refused on that portal, bound or not, and a session at it is admitted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	public function testASessionBelowTheServingPortalsFloorIsRefused(): void {
		$asked   = [];
		$serving = ['slug' => 'portal-a', 'authentication' => ['modes' => ['digid'], 'minTrust' => 'substantial']];
		$scope   = $this->scope(serving: $serving, named: null, asked: $asked);

		$this->assertFalse($scope->admits(portal: 'portal-a', trust: 'low'));
		$this->assertFalse($scope->admits(portal: '', trust: 'low'));
		$this->assertTrue($scope->admits(portal: 'portal-a', trust: 'substantial'));
		$this->assertTrue($scope->admits(portal: '', trust: 'substantial'));
		$this->assertTrue($scope->admits(portal: 'portal-a', trust: 'high'));
		$this->assertFalse($scope->admits(portal: 'portal-b', trust: 'high'));
	}//end testASessionBelowTheServingPortalsFloorIsRefused()

	/**
	 * A portal that declares no floor, or a malformed authentication block,
	 * admits a low session; an unbound session at `high` is admitted without
	 * resolving anything, and an unbound lower one is refused when the
	 * serving portal cannot be resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	public function testWithoutAFloorEverySessionMeetsIt(): void {
		$asked = [];
		$this->assertTrue($this->scope(serving: ['slug' => 'portal-a'], named: null, asked: $asked)->admits(portal: 'portal-a', trust: 'low'));
		$this->assertTrue(
			$this->scope(serving: ['slug' => 'portal-a', 'authentication' => 'not an object'], named: null, asked: $asked)->admits(portal: '', trust: 'low')
		);
		$this->assertTrue(
			$this->scope(serving: ['slug' => 'portal-a', 'authentication' => ['modes' => ['digid'], 'minTrust' => 'low']], named: null, asked: $asked)->admits(portal: '', trust: 'low')
		);

		$request  = $this->createMock(IRequest::class);
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolveForCollector')->willThrowException(new RuntimeException('down'));
		$scope = new PortalSessionScope(request: $request, portals: $resolver);
		$this->assertFalse($scope->admits(portal: '', trust: 'low'));
		$this->assertTrue($scope->admits(portal: '', trust: 'high'));
	}//end testWithoutAFloorEverySessionMeetsIt()

	/**
	 * A scope whose resolver serves the given portal and records the named portal it was asked with.
	 *
	 * @param array<string, mixed>|null $serving The serving portal.
	 * @param string|null               $named   The `portal` request parameter.
	 * @param array<int, string|null>   $asked   Collects the slug each resolution was asked with.
	 *
	 * @return PortalSessionScope
	 */
	private function scope(?array $serving, ?string $named, array &$asked): PortalSessionScope {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key) => ($key === 'portal' ? $named : null));
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolveForCollector')->willReturnCallback(
			function (IRequest $req, ?string $portalSlug = null) use ($request, $serving, &$asked): ?array {
				$this->assertSame($request, $req);
				$asked[] = $portalSlug;
				return $serving;
			}
		);

		return new PortalSessionScope(request: $request, portals: $resolver);
	}//end scope()
}//end class
