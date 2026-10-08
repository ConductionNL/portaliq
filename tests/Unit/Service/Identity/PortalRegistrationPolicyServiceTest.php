<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalRegistrationPolicyService;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases REQ-PIOC-004: a portal that
 * says nothing about self-registration does not offer it, an account under
 * either policy waits, and an address outside the allowed domains is refused.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalRegistrationPolicyServiceTest extends TestCase {

	public function testAPortalThatSaysNothingDoesNotOfferRegistration(): void {
		$policy = new PortalRegistrationPolicyService();

		$this->assertFalse($policy->isOffered(site: []));
		$this->assertSame('off', $policy->policy(site: ['authentication' => ['registration' => ['policy' => 'sure-why-not']]]));
		$this->assertFalse($policy->decide(site: [], email: 'ans@example.org')['accepted']);

	}//end testAPortalThatSaysNothingDoesNotOfferRegistration()

	public function testUnderApprovalTheAccountWaits(): void {
		$policy = new PortalRegistrationPolicyService();

		$decision = $policy->decide(site: $this->site(policy: 'approval'), email: 'ans@example.org');

		$this->assertTrue($decision['accepted']);
		$this->assertSame('approval', $decision['reason']);
		$this->assertSame('pending', $decision['status']);

	}//end testUnderApprovalTheAccountWaits()

	public function testUnderActivationTheAccountWaitsForTheMail(): void {
		$policy = new PortalRegistrationPolicyService();

		$decision = $policy->decide(site: $this->site(policy: 'activation'), email: 'ans@example.org');

		$this->assertTrue($decision['accepted']);
		$this->assertSame('activation', $decision['reason']);
		$this->assertSame('pending', $decision['status']);

	}//end testUnderActivationTheAccountWaitsForTheMail()

	public function testAnAddressOutsideTheAllowedDomainsIsRefused(): void {
		$policy = new PortalRegistrationPolicyService();
		$site = $this->site(policy: 'approval', domains: ['gemeente-x.nl']);

		$this->assertTrue($policy->decide(site: $site, email: 'ans@gemeente-x.nl')['accepted']);
		$this->assertSame('domain_not_allowed', $policy->decide(site: $site, email: 'ans@example.org')['reason']);

	}//end testAnAddressOutsideTheAllowedDomainsIsRefused()

	public function testADomainThatMerelyEndsWithAnAllowedOneIsRefused(): void {
		$policy = new PortalRegistrationPolicyService();
		$site = $this->site(policy: 'approval', domains: ['gemeente-x.nl']);

		$this->assertSame('domain_not_allowed', $policy->decide(site: $site, email: 'ans@notgemeente-x.nl')['reason']);

	}//end testADomainThatMerelyEndsWithAnAllowedOneIsRefused()

	public function testAnEmptyDomainListAllowsEveryDomain(): void {
		$policy = new PortalRegistrationPolicyService();

		$this->assertTrue($policy->decide(site: $this->site(policy: 'approval', domains: []), email: 'ans@example.org')['accepted']);

	}//end testAnEmptyDomainListAllowsEveryDomain()

	public function testSomethingThatIsNotAnAddressIsRefused(): void {
		$policy = new PortalRegistrationPolicyService();

		$this->assertSame('invalid_email', $policy->decide(site: $this->site(policy: 'approval'), email: 'ans-at-example')['reason']);

	}//end testSomethingThatIsNotAnAddressIsRefused()

	/**
	 * A portal declaring a registration policy.
	 *
	 * @param string $policy The policy.
	 * @param array<int, string> $domains The allowed domains.
	 *
	 * @return array<string, mixed>
	 */
	private function site(string $policy, array $domains = []): array {
		return ['authentication' => ['registration' => ['policy' => $policy, 'allowedDomains' => $domains]]];
	}//end site()

}//end class
