<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\PortalMandateService;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-and-the-organisations-cases REQ-PIOC-002 and REQ-PIOC-008:
 * an identity sees what its mandates cover and nothing else, a colleague with
 * no mandate recorded sees none of the organisation's cases, and the mandate
 * being acted under is one the identity actually holds.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalMandateServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	public function testAnIdentityWithNoMandateRecordedHoldsNone(): void {
		$service = new PortalMandateService($this->fakeReader());

		$this->assertSame([], $service->mandatesFor(subjectRef: 'colleague-3', organisation: 'gemeente-x'));

	}//end testAnIdentityWithNoMandateRecordedHoldsNone()

	public function testTwoEmployeesOfOneCompanyEachHoldTheirOwnMandate(): void {
		$this->seedMandate(subjectRef: 'employee-1');
		$this->seedMandate(subjectRef: 'employee-2');
		$service = new PortalMandateService($this->fakeReader());

		$this->assertCount(1, $service->mandatesFor(subjectRef: 'employee-1', organisation: 'gemeente-x'));
		$this->assertCount(1, $service->mandatesFor(subjectRef: 'employee-2', organisation: 'gemeente-x'));
		$this->assertSame([], $service->mandatesFor(subjectRef: 'employee-3', organisation: 'gemeente-x'));

	}//end testTwoEmployeesOfOneCompanyEachHoldTheirOwnMandate()

	public function testARevokedMandateGrantsNothing(): void {
		$this->seedMandate(subjectRef: 'employee-1', extra: ['status' => 'revoked']);
		$service = new PortalMandateService($this->fakeReader());

		$this->assertSame([], $service->mandatesFor(subjectRef: 'employee-1', organisation: 'gemeente-x'));

	}//end testARevokedMandateGrantsNothing()

	public function testAnExpiredMandateGrantsNothing(): void {
		$this->seedMandate(subjectRef: 'employee-1', extra: ['expiresAt' => '2026-01-01T00:00:00+00:00']);
		$service = new PortalMandateService($this->fakeReader());

		$held = $service->mandatesFor(subjectRef: 'employee-1', organisation: 'gemeente-x', now: new DateTimeImmutable('2026-09-18T00:00:00+00:00'));

		$this->assertSame([], $held);

	}//end testAnExpiredMandateGrantsNothing()

	public function testAnUnreadableExpiryGrantsNothing(): void {
		$this->seedMandate(subjectRef: 'employee-1', extra: ['expiresAt' => 'whenever']);
		$service = new PortalMandateService($this->fakeReader());

		$this->assertSame([], $service->mandatesFor(subjectRef: 'employee-1', organisation: 'gemeente-x'));

	}//end testAnUnreadableExpiryGrantsNothing()

	public function testANarrowMandateCoversOnlyTheTypesItNames(): void {
		$service = new PortalMandateService($this->fakeReader());
		$narrow = ['caseTypes' => ['vergunning']];

		$this->assertTrue($service->covers(mandate: $narrow, caseType: 'vergunning'));
		$this->assertFalse($service->covers(mandate: $narrow, caseType: 'melding'));
		$this->assertTrue($service->covers(mandate: ['caseTypes' => []], caseType: 'melding'));

	}//end testANarrowMandateCoversOnlyTheTypesItNames()

	public function testSwitchingToAMandateTheIdentityDoesNotHoldSelectsNothing(): void {
		$this->seedMandate(subjectRef: 'employee-1');
		$service = new PortalMandateService($this->fakeReader());
		$held = $service->mandatesFor(subjectRef: 'employee-1', organisation: 'gemeente-x');

		$this->assertNull($service->activeMandate(mandates: $held, mandateId: 'somebody-elses-mandate'));
		$this->assertSame($held[0], $service->activeMandate(mandates: $held));

	}//end testSwitchingToAMandateTheIdentityDoesNotHoldSelectsNothing()

	public function testSwitchingBetweenTwoOfTheirOwnMandatesPicksTheNamedOne(): void {
		$first = $this->seedMandate(subjectRef: 'employee-1', extra: ['onBehalfOf' => 'kvk-1']);
		$second = $this->seedMandate(subjectRef: 'employee-1', extra: ['onBehalfOf' => 'kvk-2']);
		$service = new PortalMandateService($this->fakeReader());
		$held = $service->mandatesFor(subjectRef: 'employee-1', organisation: 'gemeente-x');

		$active = $service->activeMandate(mandates: $held, mandateId: $second);

		$this->assertNotNull($active);
		$this->assertSame('kvk-2', $service->describe(mandate: $active)['onBehalfOf']);
		$this->assertNotSame($first, $service->mandateId(mandate: $active));

	}//end testSwitchingBetweenTwoOfTheirOwnMandatesPicksTheNamedOne()

	public function testTheMandateNamesItselfForTheView(): void {
		$this->seedMandate(subjectRef: 'employee-1', extra: ['label' => 'Gemachtigd voor Voorbeeld B.V.']);
		$service = new PortalMandateService($this->fakeReader());
		$held = $service->mandatesFor(subjectRef: 'employee-1', organisation: 'gemeente-x');

		$described = $service->describe(mandate: $held[0]);

		$this->assertSame('Gemachtigd voor Voorbeeld B.V.', $described['label']);
		$this->assertSame('gemeente-x', $described['organisation']);

	}//end testTheMandateNamesItselfForTheView()

	/**
	 * Put one live mandate in the fake store.
	 *
	 * @param string $subjectRef The identity holding it.
	 * @param array<string, mixed> $extra Anything to override.
	 *
	 * @return string The mandate's id.
	 */
	private function seedMandate(string $subjectRef, array $extra = []): string {
		return $this->seedRow('portalMandate', array_merge([
			'subjectRef' => $subjectRef,
			'organisation' => 'gemeente-x',
			'onBehalfOf' => 'kvk-12345678',
			'label' => 'Voorbeeld B.V.',
			'caseTypes' => [],
			'status' => 'active',
		], $extra));
	}//end seedMandate()


	/**
	 * 🔴 THE REACH DECIDES HOW FAR A MANDATE SEES, AND NOTHING EXECUTED IT.
	 *
	 * `reachOf()` has two real callers, `MyCasesController` and
	 * `CitizenCaseController`, and both use it to decide `reachesDown`: whether
	 * a citizen's case list walks down the party tree into subsidiaries. So it
	 * is the method that decides whether one mandate sees one organisation or a
	 * whole group.
	 *
	 * 🔑 THE CONTROLLER TEST MOCKS IT. `MyCasesControllerTest` builds the
	 * service with `onlyMethods([... 'reachOf'])` and forces the answer, which
	 * is right for a controller test and means the REAL method was never run by
	 * anything. Had a refactor inverted this condition, or accepted any truthy
	 * value, every mandate would have reached the whole subtree and every test
	 * in this repo would still have passed.
	 *
	 * @return void
	 */
	public function testAMandateDeclaringTheWiderReachGetsIt(): void {
		$service = new PortalMandateService($this->fakeReader());

		$this->assertSame(
			PortalMandateService::REACH_TREE,
			$service->reachOf(mandate: ['reach' => PortalMandateService::REACH_TREE])
		);
	}//end testAMandateDeclaringTheWiderReachGetsIt()

	/**
	 * 🔴 A MANDATE THAT SAYS NOTHING REACHES ONE ORGANISATION, NOT THE TREE.
	 *
	 * This is the fail-closed default and the assertion that matters. Every
	 * mandate written before the reach existed says nothing, so the default is
	 * what those mandates get, and the wrong default would silently widen all
	 * of them to the whole group.
	 *
	 * @return void
	 */
	public function testAMandateThatSaysNothingReachesOnlyItsOwnOrganisation(): void {
		$service = new PortalMandateService($this->fakeReader());

		$this->assertSame(
			PortalMandateService::REACH_ORGANISATION,
			$service->reachOf(mandate: []),
			'A mandate that declares no reach must not be widened to the party tree.'
		);
	}//end testAMandateThatSaysNothingReachesOnlyItsOwnOrganisation()

	/**
	 * A reach nobody recognises is the narrow one, not a guess.
	 *
	 * `tree ` with a trailing space, `TREE`, `subsidiaries`, `true`: each is
	 * somebody trying to say the wider thing and failing, and each must land on
	 * the narrow answer rather than the one they were reaching for. Anything
	 * else means a typo widens visibility.
	 *
	 * @return void
	 */
	public function testAReachNobodyRecognisesIsTheNarrowOne(): void {
		$service = new PortalMandateService($this->fakeReader());

		foreach (['tree ', 'TREE', 'subsidiaries', 'all', '1', 'organisation'] as $declared) {
			$this->assertSame(
				PortalMandateService::REACH_ORGANISATION,
				$service->reachOf(mandate: ['reach' => $declared]),
				sprintf('\'%s\' must not be read as the wider reach.', $declared)
			);
		}
	}//end testAReachNobodyRecognisesIsTheNarrowOne()

	/**
	 * A non-string reach is the narrow one too.
	 *
	 * A JSON edit can easily produce `true` or a list here, and reading either
	 * as the wider reach would widen a mandate nobody widened.
	 *
	 * @return void
	 */
	public function testANonStringReachIsTheNarrowOne(): void {
		$service = new PortalMandateService($this->fakeReader());

		$this->assertSame(
			PortalMandateService::REACH_ORGANISATION,
			$service->reachOf(mandate: ['reach' => true])
		);
	}//end testANonStringReachIsTheNarrowOne()

	/**
	 * `describe()` reports the same reach `reachOf()` decides.
	 *
	 * The description is what a surface renders beside a case, so a description
	 * that disagreed with the decision would show somebody a scope they do not
	 * have, or hide one they do.
	 *
	 * @return void
	 */
	public function testTheDescriptionReportsTheReachThatWasDecided(): void {
		$service = new PortalMandateService($this->fakeReader());

		foreach ([['reach' => PortalMandateService::REACH_TREE], ['reach' => 'nonsense'], []] as $mandate) {
			$this->assertSame(
				$service->reachOf(mandate: $mandate),
				($service->describe(mandate: $mandate)['reach'] ?? null),
				'describe() and reachOf() must not disagree about how far a mandate reaches.'
			);
		}
	}//end testTheDescriptionReportsTheReachThatWasDecided()
}//end class
