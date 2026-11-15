<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\CmsDomainController;
use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
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
		$c = new CmsDomainController('portaliq', $this->createMock(IRequest::class), $verifier, $this->l10n(), $this->scope(may: true));

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
		$c = new CmsDomainController('portaliq', $this->createMock(IRequest::class), $verifier, $this->l10n(), $this->scope(may: true));

		$this->assertSame(['status' => 'verified', 'message' => 'a.nl is verified.'], $c->verify('zuid', 'a.nl')->getData());
		$this->assertSame(['status' => 'pending', 'message' => 'The record of a.nl is not there yet.'], $c->verify('zuid', 'a.nl')->getData());
		$this->assertSame(404, $c->verify('zuid', 'b.nl')->getStatus());
		$this->assertSame(404, $c->verify('zuid', 'c.nl')->getStatus());
	}//end testVerify()

	/**
	 * A portal the caller may not administer answers the unknown-portal 404, and nothing is read or checked.
	 *
	 * @return void
	 */
	public function testAPortalTheCallerMayNotAdministerIs404(): void {
		$verifier = $this->createMock(PortalDomainVerifier::class);
		$verifier->expects($this->never())->method('records');
		$verifier->expects($this->never())->method('verify');
		$scope = $this->scope(may: false);
		$c = new CmsDomainController('portaliq', $this->createMock(IRequest::class), $verifier, $this->l10n(), $scope);

		$this->assertSame(['error' => 'not_found'], $c->records('utrecht')->getData());
		$this->assertSame(404, $c->records('utrecht')->getStatus());
		$this->assertSame(404, $c->verify('utrecht', 'a.nl')->getStatus());
	}//end testAPortalTheCallerMayNotAdministerIs404()

	/**
	 * Both routes take the portal administrators' rule, not the instance-admin default.
	 *
	 * @return void
	 */
	public function testBothRoutesAreForThePortalsAdministrators(): void {
		foreach (['records', 'verify'] as $method) {
			$names = array_map(
				static fn ($attribute): string => $attribute->getName(),
				(new \ReflectionMethod(CmsDomainController::class, $method))->getAttributes()
			);
			$this->assertContains('OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired', $names);
			$this->assertNotContains('OCP\\AppFramework\\Http\\Attribute\\PublicPage', $names);
		}
	}//end testBothRoutesAreForThePortalsAdministrators()

	/**
	 * A scope that answers the same for every slug, asked with the slug of the request.
	 *
	 * @param bool $may What it answers.
	 *
	 * @return PortalAdminScope
	 */
	private function scope(bool $may): PortalAdminScope {
		$scope = $this->createMock(PortalAdminScope::class);
		$scope->method('mayAdministerSlug')->willReturnCallback(
			static fn (string $slug): bool => ($slug !== '' && $may)
		);
		return $scope;
	}//end scope()

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
}//end class
