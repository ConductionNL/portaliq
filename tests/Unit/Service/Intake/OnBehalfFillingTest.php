<?php

/**
 * Tests for OnBehalfFilling (resident-identity-in-forms T06).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
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

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Kernel\Contracts\PortalRegisteredDetailsServiceInterface;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Intake\OnBehalfFilling;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who may fill a form in for a resident, who the applicant is, and what the
 * registers say about them.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
class OnBehalfFillingTest extends TestCase {

	private const SITE = ['slug' => 'gemeente-x', 'organisation' => 'org-x', 'authentication' => ['deskGroup' => ' balie ']];

	private const BINDING = ['staffMayFill' => true];

	private IGroupManager&MockObject $groups;

	private IUserManager&MockObject $users;

	private PortalRegisteredDetailsServiceInterface&MockObject $details;

	private AuditTrailService&MockObject $audit;

	private OnBehalfFilling $filling;

	protected function setUp(): void {
		$this->groups  = $this->createMock(IGroupManager::class);
		$this->users   = $this->createMock(IUserManager::class);
		$this->details = $this->createMock(PortalRegisteredDetailsServiceInterface::class);
		$this->audit   = $this->createMock(AuditTrailService::class);
		$this->filling = new OnBehalfFilling(groups: $this->groups, users: $this->users, details: $this->details, audit: $this->audit);
	}//end setUp()

	public function testAMemberOfTheDeskGroupIsTheEmployeeWithTheirDisplayName(): void {
		$this->groups->expects($this->once())->method('isInGroup')->with('balie1', 'balie')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Bart Balie');
		$this->users->method('get')->with('balie1')->willReturn($user);

		$this->assertSame(['uid' => 'balie1', 'displayName' => 'Bart Balie'], $this->filling->employee(site: self::SITE, binding: self::BINDING, uid: 'balie1'));
	}//end testAMemberOfTheDeskGroupIsTheEmployeeWithTheirDisplayName()

	public function testAnEmployeeWithoutADisplayNameIsNamedByTheirUid(): void {
		$this->groups->method('isInGroup')->willReturn(true);
		$this->users->method('get')->willReturn(null);

		$this->assertSame(['uid' => 'balie1', 'displayName' => 'balie1'], $this->filling->employee(site: self::SITE, binding: self::BINDING, uid: 'balie1'));
	}//end testAnEmployeeWithoutADisplayNameIsNamedByTheirUid()

	public function testNoEmployeeOutsideTheGroupOrWithoutAGroupOrOnAFormThatDoesNotAllowIt(): void {
		$this->groups->method('isInGroup')->willReturn(false);

		$this->assertNull($this->filling->employee(site: self::SITE, binding: self::BINDING, uid: 'balie1'));
		$this->assertNull($this->filling->employee(site: ['authentication' => ['deskGroup' => '']], binding: self::BINDING, uid: 'balie1'));
		$this->assertNull($this->filling->employee(site: ['authentication' => ['deskGroup' => ['balie']]], binding: self::BINDING, uid: 'balie1'));
		$this->assertNull($this->filling->employee(site: self::SITE, binding: ['staffMayFill' => 'yes'], uid: 'balie1'));
		$this->assertNull($this->filling->employee(site: self::SITE, binding: self::BINDING, uid: ''));
	}//end testNoEmployeeOutsideTheGroupOrWithoutAGroupOrOnAFormThatDoesNotAllowIt()

	public function testTheGroupIsNotAskedWhenTheFormDoesNotAllowStaff(): void {
		$this->groups->expects($this->never())->method('isInGroup');

		$this->assertNull($this->filling->employee(site: self::SITE, binding: [], uid: 'balie1'));
	}//end testTheGroupIsNotAskedWhenTheFormDoesNotAllowStaff()

	public function testAnApplicantIsAValidBsnKvkNumberOrName(): void {
		$this->assertSame(['kind' => 'bsn', 'value' => '999993653'], $this->filling->applicant(raw: ['kind' => 'bsn', 'value' => ' 999993653 ']));
		$this->assertSame(['kind' => 'kvk', 'value' => '12345678'], $this->filling->applicant(raw: ['kind' => 'kvk', 'value' => '12345678']));
		$this->assertSame(['kind' => 'named', 'value' => 'Sanne de Vries'], $this->filling->applicant(raw: ['kind' => 'named', 'value' => 'Sanne de Vries']));
	}//end testAnApplicantIsAValidBsnKvkNumberOrName()

	public function testAnythingElseIsNoApplicant(): void {
		$this->assertNull($this->filling->applicant(raw: ['kind' => 'bsn', 'value' => '123456789']));
		$this->assertNull($this->filling->applicant(raw: ['kind' => 'kvk', 'value' => '1234567']));
		$this->assertNull($this->filling->applicant(raw: ['kind' => 'named', 'value' => '  ']));
		$this->assertNull($this->filling->applicant(raw: ['kind' => 'named', 'value' => str_repeat('a', 201)]));
		$this->assertNull($this->filling->applicant(raw: ['kind' => 'passport', 'value' => 'X1']));
		$this->assertNull($this->filling->applicant(raw: ['kind' => 'bsn', 'value' => 999993653]));
		$this->assertNull($this->filling->applicant(raw: 'bsn:999993653'));
	}//end testAnythingElseIsNoApplicant()

	public function testACompanyLookupIsAuditedAndFillsTheNameAndNumber(): void {
		$this->audit->expects($this->once())->method('record')->with(
			OnBehalfFilling::LOOKUP_VERB,
			'balie1',
			'org-x',
			'kvk',
			'kvk',
			'',
			'',
			\OCA\Portaliq\Kernel\AppIdentity::APP_ID,
			['employee' => 'balie1', 'kind' => 'kvk', 'value' => '12345678']
		);
		$this->details->expects($this->once())->method('forKvkNumber')->with('12345678')
			->willReturn(['available' => true, 'kind' => 'company', 'company' => ['tradeName' => 'Bakkerij de Korenschoof', 'kvkNumber' => '12345678']]);
		$this->details->expects($this->never())->method('forBsn');

		$found = $this->filling->lookUp(
			site: self::SITE,
			employee: ['uid' => 'balie1', 'displayName' => 'Bart Balie'],
			applicant: ['kind' => 'kvk', 'value' => '12345678'],
			fields: [['name' => 'applicantName'], ['name' => 'applicantKvk'], ['name' => 'applicantStreet'], 'not-a-field']
		);

		$this->assertSame(['available' => true, 'prefill' => ['applicantName' => 'Bakkerij de Korenschoof', 'applicantKvk' => '12345678'], 'reason' => ''], $found);
	}//end testACompanyLookupIsAuditedAndFillsTheNameAndNumber()

	public function testAPersonLookupFillsTheAddressFieldsTheFormHas(): void {
		$this->details->method('forBsn')->willReturn([
			'available' => true,
			'kind' => 'person',
			'person' => ['name' => 'Sanne de Vries', 'birthDate' => '1990-01-02', 'address' => ['street' => 'Dorpsstraat', 'number' => '12A', 'postcode' => '1234AB', 'city' => '']],
		]);

		$found = $this->filling->lookUp(
			site: self::SITE,
			employee: ['uid' => 'balie1', 'displayName' => 'Bart Balie'],
			applicant: ['kind' => 'bsn', 'value' => '999993653'],
			fields: [['name' => 'applicantStreet'], ['name' => 'applicantHouseNumber'], ['name' => 'applicantCity'], ['name' => 'applicantBirthDate']]
		);

		// An empty value is not prefilled.
		$this->assertSame(['applicantStreet' => 'Dorpsstraat', 'applicantHouseNumber' => '12A', 'applicantBirthDate' => '1990-01-02'], $found['prefill']);
	}//end testAPersonLookupFillsTheAddressFieldsTheFormHas()

	public function testALookupTheRegisterCannotAnswerSaysWhyAndIsStillAudited(): void {
		$this->audit->expects($this->once())->method('record');
		$this->details->method('forBsn')->willReturn(['available' => false, 'reason' => 'source_unavailable']);

		$found = $this->filling->lookUp(site: self::SITE, employee: ['uid' => 'balie1', 'displayName' => 'B'], applicant: ['kind' => 'bsn', 'value' => '999993653'], fields: [['name' => 'applicantName']]);

		$this->assertSame(['available' => false, 'prefill' => [], 'reason' => 'source_unavailable'], $found);
	}//end testALookupTheRegisterCannotAnswerSaysWhyAndIsStillAudited()

	public function testANamedApplicantIsNotLookedUpOrAudited(): void {
		$this->audit->expects($this->never())->method('record');
		$this->details->expects($this->never())->method('forBsn');
		$this->details->expects($this->never())->method('forKvkNumber');

		$found = $this->filling->lookUp(site: self::SITE, employee: ['uid' => 'balie1', 'displayName' => 'B'], applicant: ['kind' => 'named', 'value' => 'Sanne de Vries'], fields: [['name' => 'applicantName']]);

		$this->assertSame(['available' => true, 'prefill' => ['applicantName' => 'Sanne de Vries'], 'reason' => ''], $found);
	}//end testANamedApplicantIsNotLookedUpOrAudited()

	public function testTheServerValuesNameTheEmployeeAndTheApplicant(): void {
		$this->assertSame(
			['filledBy' => ['uid' => 'balie1', 'displayName' => 'Bart Balie'], 'applicant' => ['kind' => 'named', 'value' => 'Sanne']],
			$this->filling->serverValues(employee: ['uid' => 'balie1', 'displayName' => 'Bart Balie'], applicant: ['kind' => 'named', 'value' => 'Sanne'])
		);
	}//end testTheServerValuesNameTheEmployeeAndTheApplicant()
}//end class
