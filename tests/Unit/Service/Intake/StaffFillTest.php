<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use OCA\Portaliq\Service\Intake\DutchFormats;
use OCA\Portaliq\Service\Intake\StaffFill;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * resident-identity-in-forms REQ-RIF-005: only a desk employee on a form that
 * allows it may fill in for someone else, the applicant is checked, and every
 * BRP or KvK lookup writes an audit line naming the employee.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
class StaffFillTest extends TestCase {

	private const SITE = ['slug' => 'zuiddrecht', 'organisation' => 'org-1', 'authentication' => ['deskGroup' => 'balie']];

	private const BINDING = ['route' => 'woo', 'staffMayFill' => true];

	/** @var array<int, array<string, mixed>> */
	private array $audited = [];

	private PortalAccountLookup&MockObject $accounts;

	private PortalRegisteredDetailsService&MockObject $registers;

	private function staff(?string $uid = 'medewerker', bool $inGroup = true, ?array $ownAccount = null): StaffFill {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn('Mieke Balie');
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $who, string $group): bool => $inGroup === true && $group === 'balie');

		$this->accounts = $this->getMockBuilder(PortalAccountLookup::class)->disableOriginalConstructor()->onlyMethods(['bySubjectRef', 'activeByCitizenNumber'])->getMock();
		$this->accounts->method('bySubjectRef')->willReturn($ownAccount);

		$this->registers = $this->getMockBuilder(PortalRegisteredDetailsService::class)->disableOriginalConstructor()->onlyMethods(['forPerson', 'forCompany'])->getMock();

		$audit = $this->getMockBuilder(AuditTrailService::class)->disableOriginalConstructor()->onlyMethods(['record'])->getMock();
		$audit->method('record')->willReturnCallback(function (...$args): void {
			$this->audited[] = $args;
		});

		return new StaffFill($session, $groups, $this->accounts, $this->registers, new DutchFormats(), $audit);
	}//end staff()

	public function testADeskEmployeeOnAFormThatAllowsItMayFillIn(): void {
		$this->assertSame(['uid' => 'medewerker', 'displayName' => 'Mieke Balie'], $this->staff()->employee(self::SITE, self::BINDING));
	}//end testADeskEmployeeOnAFormThatAllowsItMayFillIn()

	public function testStaffModeIsRefusedOutsideTheDeskGroupAndWithoutTheSettings(): void {
		$this->assertNull($this->staff(inGroup: false)->employee(self::SITE, self::BINDING), 'not in the desk group');
		$this->assertNull($this->staff(uid: null)->employee(self::SITE, self::BINDING), 'not signed in');
		$this->assertNull($this->staff()->employee(self::SITE, ['route' => 'woo']), 'the form does not allow employees');
		$this->assertNull($this->staff()->employee(['slug' => 'zuiddrecht'], self::BINDING), 'the portal names no desk group');
		$this->assertNull($this->staff(ownAccount: ['subjectRef' => 'medewerker'])->employee(self::SITE, self::BINDING), 'a resident with a portal account is no employee');
	}//end testStaffModeIsRefusedOutsideTheDeskGroupAndWithoutTheSettings()

	public function testTheApplicantIsCheckedAndNormalised(): void {
		$staff = $this->staff();

		$this->assertSame(['kind' => 'bsn', 'value' => '111222333'], $staff->applicant(['kind' => 'bsn', 'value' => ' 1112.22.333 ']));
		$this->assertSame(['kind' => 'kvk', 'value' => '12345678'], $staff->applicant(['kind' => 'kvk', 'value' => '1234 5678']));
		$this->assertSame(['kind' => 'named', 'value' => 'Sanne de Vries'], $staff->applicant(['kind' => 'named', 'value' => "  Sanne \n de Vries "]));
		$this->assertNull($staff->applicant(['kind' => 'bsn', 'value' => '111222334']), 'fails the eleven test');
		$this->assertNull($staff->applicant(['kind' => 'kvk', 'value' => '1234']));
		$this->assertNull($staff->applicant(['kind' => 'named', 'value' => '   ']));
		$this->assertNull($staff->applicant(['kind' => 'named', 'value' => str_repeat('a', 201)]));
		$this->assertNull($staff->applicant(['kind' => 'self', 'value' => 'x']));
		$this->assertNull($staff->applicant([]));
	}//end testTheApplicantIsCheckedAndNormalised()

	public function testABsnIsLookedUpInTheBrpAndAudited(): void {
		$staff = $this->staff();
		$this->registers->expects($this->once())->method('forPerson')->with('111222333')->willReturn(['available' => true, 'person' => ['name' => 'Sanne de Vries']]);

		$prefill = $staff->prefill(['uid' => 'medewerker', 'displayName' => 'Mieke Balie'], ['kind' => 'bsn', 'value' => '111222333'], self::SITE);

		$this->assertSame(['applicantName' => 'Sanne de Vries'], $prefill);
		$this->assertCount(1, $this->audited);
		$this->assertSame(['staff-lookup', 'medewerker', 'org-1', 'brp', 'person', ''], array_slice($this->audited[0], 0, 6));
		$this->assertSame(['employee' => 'medewerker', 'kind' => 'bsn', 'value' => '111222333'], $this->audited[0][8]);
		$this->assertContains(StaffFill::AUDIT_VERB, AuditTrailService::VERBS);
	}//end testABsnIsLookedUpInTheBrpAndAudited()

	public function testAKvkNumberIsLookedUpAndAuditedEvenWhenTheRegisterIsDown(): void {
		$staff = $this->staff();
		$this->registers->method('forCompany')->willReturn(['available' => false, 'reason' => 'source_unavailable']);

		$this->assertSame([], $staff->prefill(['uid' => 'medewerker', 'displayName' => 'Mieke Balie'], ['kind' => 'kvk', 'value' => '12345678'], self::SITE));
		$this->assertCount(1, $this->audited);
		$this->assertSame('kvk', $this->audited[0][3]);
	}//end testAKvkNumberIsLookedUpAndAuditedEvenWhenTheRegisterIsDown()

	public function testANamedApplicantIsNotLookedUp(): void {
		$staff = $this->staff();
		$this->registers->expects($this->never())->method('forPerson');

		$this->assertSame(['applicantName' => 'Henk de Vries'], $staff->prefill(['uid' => 'medewerker', 'displayName' => 'Mieke Balie'], ['kind' => 'named', 'value' => 'Henk de Vries'], self::SITE));
		$this->assertSame([], $this->audited);
	}//end testANamedApplicantIsNotLookedUp()

	public function testTheRequestIsFiledUnderTheResidentsOwnAccountWhenThereIsOne(): void {
		$staff = $this->staff();
		$this->accounts->method('activeByCitizenNumber')->willReturnMap([['111222333', ['subjectRef' => 'sub-sanne']], ['999993653', null]]);

		$this->assertSame('sub-sanne', $staff->subjectRefFor(['kind' => 'bsn', 'value' => '111222333']));
		$this->assertSame('', $staff->subjectRefFor(['kind' => 'bsn', 'value' => '999993653']));
		$this->assertSame('', $staff->subjectRefFor(['kind' => 'kvk', 'value' => '12345678']));
	}//end testTheRequestIsFiledUnderTheResidentsOwnAccountWhenThereIsOne()
}//end class
