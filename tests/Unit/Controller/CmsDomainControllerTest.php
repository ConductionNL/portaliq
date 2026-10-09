<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\CmsDomainController;
use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
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
		$c = new CmsDomainController('portaliq', $this->createMock(IRequest::class), $verifier);

		$this->assertSame(['domains' => [['host' => 'a.nl']]], $c->records('zuid')->getData());
		$this->assertSame(404, $c->records('nope')->getStatus());
	}//end testRecords()

	/**
	 * Verify answers the status; unknown portal or host is a 404.
	 *
	 * @return void
	 */
	public function testVerify(): void {
		$verifier = $this->createMock(PortalDomainVerifier::class);
		$verifier->method('verify')->willReturnOnConsecutiveCalls('verified', null, PortalDomainVerifier::NOT_FOUND);
		$c = new CmsDomainController('portaliq', $this->createMock(IRequest::class), $verifier);

		$this->assertSame(['status' => 'verified'], $c->verify('zuid', 'a.nl')->getData());
		$this->assertSame(404, $c->verify('zuid', 'b.nl')->getStatus());
		$this->assertSame(404, $c->verify('zuid', 'c.nl')->getStatus());
	}//end testVerify()
}//end class
