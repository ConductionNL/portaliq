<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCA\Portaliq\Controller\CmsDomainController;
use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The custom-domain records and verification endpoints.
 */
#[CoversClass(CmsDomainController::class)]
class CmsDomainControllerTest extends TestCase {
	/**
	 * Records answer the domains or a 404.
	 *
	 * @return void
	 */
	public function testRecords(): void {
		$verifier = $this->createMock(PortalDomainVerifier::class);
		$verifier->method('records')->willReturnOnConsecutiveCalls([['host' => 'a.nl']], null);
		$c = new CmsDomainController('portaliq', $this->createMock(IRequest::class), $verifier, $this->l10n(), $this->scope());

		$this->assertSame(['domains' => [['host' => 'a.nl']]], $c->records('zuid')->getData());
		$this->assertSame(404, $c->records('nope')->getStatus());
	}//end testRecords()

	/**
	 * Verify answers the status and a sentence for it; unknown portal or host is a 404.
	 *
	 * @return void
	 */
	public function testVerify(): void {
		$verifier = $this->createMock(PortalDomainVerifier::class);
		$verifier->method('verify')->willReturnOnConsecutiveCalls('verified', 'pending', null, PortalDomainVerifier::NOT_FOUND);
		$c = new CmsDomainController('portaliq', $this->createMock(IRequest::class), $verifier, $this->l10n(), $this->scope());

		$this->assertSame(['status' => 'verified', 'message' => 'a.nl is verified.'], $c->verify('zuid', 'a.nl')->getData());
		$this->assertSame(['status' => 'pending', 'message' => 'The record of a.nl is not there yet.'], $c->verify('zuid', 'a.nl')->getData());
		$this->assertSame(404, $c->verify('zuid', 'b.nl')->getStatus());
		$this->assertSame(404, $c->verify('zuid', 'c.nl')->getStatus());
	}//end testVerify()

	/**
	 * Another organisation's portal answers 404, and its records are not read or checked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public function testAPortalTheCallerDoesNotAdministerIs404(): void {
		$verifier = $this->createMock(PortalDomainVerifier::class);
		$verifier->expects($this->never())->method('records');
		$verifier->expects($this->never())->method('verify');
		$c = new CmsDomainController('portaliq', $this->createMock(IRequest::class), $verifier, $this->l10n(), $this->scope(allowed: false));

		$this->assertSame(404, $c->records('utrecht')->getStatus());
		$this->assertSame(404, $c->verify('utrecht', 'utrecht.nl')->getStatus());
	}//end testAPortalTheCallerDoesNotAdministerIs404()

	/**
	 * An IL10N that fills the placeholders and translates nothing.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters));
		return $l10n;
	}//end l10n()

	/**
	 * A portal scope that lets the caller administer every portal, or none.
	 *
	 * @param bool $allowed Whether the caller administers the portal.
	 *
	 * @return PortalAdminScope
	 */
	private function scope(bool $allowed = true): PortalAdminScope {
		$scope = $this->getMockBuilder(PortalAdminScope::class)
			->disableOriginalConstructor()
			->onlyMethods(['mayAdminister', 'mayAdministerSlug'])
			->getMock();
		$scope->method('mayAdminister')->willReturn($allowed);
		$scope->method('mayAdministerSlug')->willReturn($allowed);

		return $scope;
	}//end scope()
}//end class
