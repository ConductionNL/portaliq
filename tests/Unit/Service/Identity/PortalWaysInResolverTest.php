<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Identity\PortalReferenceLinkService;
use OCA\Portaliq\Service\Identity\PortalRegistrationPolicyService;
use OCA\Portaliq\Service\Identity\PortalWaysInResolver;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * identity-ways-in-screens T07 (REQ-IWI-005): the sign-in screen shows a door
 * only when the path behind it works. The policy and the reference admission
 * are the real services; the bindings and the case types are the portal's.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-the-sign-in-screen-shows-only-the-doors-that-lead-somewhere-req-iwi-005
 */
class PortalWaysInResolverTest extends TestCase {

	private const EMAIL = ['provider' => 'generic', 'label' => 'E-mail', 'route' => 'broker'];

	private const DIGID = ['provider' => 'digid', 'label' => 'DigiD', 'route' => 'broker'];

	public function testRegistrationNeedsAPolicyAndAnEmailSignIn(): void {
		$ways = $this->resolver()->waysIn(portal: $this->portal('activation'), oidcProviders: [self::DIGID, self::EMAIL]);

		$this->assertTrue($ways['register']);
		$this->assertSame('E-mail', $ways['emailSignIn']);

	}//end testRegistrationNeedsAPolicyAndAnEmailSignIn()

	public function testNoEmailSignInNoRegistration(): void {
		$ways = $this->resolver()->waysIn(portal: $this->portal('approval'), oidcProviders: [self::DIGID]);

		$this->assertFalse($ways['register']);
		$this->assertSame('', $ways['emailSignIn']);

	}//end testNoEmailSignInNoRegistration()

	public function testRegistrationOffNoRegistration(): void {
		$ways = $this->resolver()->waysIn(portal: $this->portal('off'), oidcProviders: [self::EMAIL]);

		$this->assertFalse($ways['register']);

	}//end testRegistrationOffNoRegistration()

	public function testTheReferenceDoorListsOnlyCaseTypesThatAdmitIt(): void {
		$types = [
			'type-ref' => ['title' => 'Parkeervergunning', 'portalIdentityKind' => ['account', 'reference']],
			'type-acc' => ['title' => 'Bezwaar', 'portalIdentityKind' => ['account']],
		];
		$ways = $this->resolver(
			declared: [['dossiq', 'caseType', 'type-ref'], ['dossiq', 'caseType', 'type-acc']],
			types: $types
		)->waysIn(portal: $this->portal('off'), oidcProviders: []);

		$this->assertTrue($ways['reference']);
		$this->assertSame(
			[['register' => 'dossiq', 'schema' => 'caseType', 'caseType' => 'type-ref', 'label' => 'Parkeervergunning']],
			$ways['referenceCaseTypes']
		);

	}//end testTheReferenceDoorListsOnlyCaseTypesThatAdmitIt()

	public function testNoCaseTypeAdmittingAReferenceNoReferenceDoor(): void {
		$ways = $this->resolver(
			declared: [['dossiq', 'caseType', 'type-acc']],
			types: ['type-acc' => ['title' => 'Bezwaar', 'portalIdentityKind' => ['account']]]
		)->waysIn(portal: $this->portal('off'), oidcProviders: []);

		$this->assertFalse($ways['reference']);
		$this->assertSame([], $ways['referenceCaseTypes']);

	}//end testNoCaseTypeAdmittingAReferenceNoReferenceDoor()

	/**
	 * A portal with a registration policy.
	 *
	 * @param string $policy The policy.
	 *
	 * @return array<string, mixed>
	 */
	private function portal(string $policy): array {
		return ['slug' => 'gemeente-x', 'organisation' => 'gemeente-x', 'authentication' => ['registration' => ['policy' => $policy]]];
	}//end portal()

	/**
	 * The resolver over the real policy and reference services.
	 *
	 * @param array<int, array<int, string>> $declared The portal's bound case types.
	 * @param array<string, array<string, mixed>> $types The case types by id.
	 *
	 * @return PortalWaysInResolver
	 */
	private function resolver(array $declared = [], array $types = []): PortalWaysInResolver {
		$bindings = $this->getMockBuilder(PortalFormBindingResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['declaredCaseTypes'])
			->getMock();
		$bindings->method('declaredCaseTypes')->willReturn($declared);

		$caseTypes = $this->getMockBuilder(CaseTypeReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCaseType'])
			->getMock();
		$caseTypes->method('readCaseType')->willReturnCallback(
			fn (string $register, string $schema, string $id): ?array => ($types[$id] ?? null)
		);

		$references = new PortalReferenceLinkService(
			$this->createMock(PortalObjectReader::class),
			$this->createMock(PortalObjectWriter::class),
			$this->createMock(ISecureRandom::class)
		);

		return new PortalWaysInResolver(new PortalRegistrationPolicyService(), $bindings, $caseTypes, $references);
	}//end resolver()
}//end class
