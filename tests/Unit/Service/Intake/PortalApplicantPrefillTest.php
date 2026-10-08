<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalApplicantPrefill;
use OCA\Portaliq\Service\PortalAccountService;
use PHPUnit\Framework\TestCase;

/**
 * portal-intake-form-as-an-object REQ-PIFO-003: the applicant block is filled
 * from the signed-in identity's own claims, an anonymous visitor gets an empty
 * block with no hint that a value existed, and no other identity's claim ever
 * appears in the answer.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalApplicantPrefillTest extends TestCase {

	public function testASignedInCitizenDoesNotRetypeTheirName(): void {
		$prefill = $this->prefill(['subject-1' => ['subjectRef' => 'subject-1', 'displayName' => 'Ans de Vries', 'email' => 'ans@example.org']]);

		$values = $prefill->forSubject(subject: ['subjectRef' => 'subject-1'], fields: $this->applicantFields());

		$this->assertSame('Ans de Vries', $values['applicantName']);
		$this->assertSame('ans@example.org', $values['applicantEmail']);

	}//end testASignedInCitizenDoesNotRetypeTheirName()

	public function testAnAnonymousVisitorGetsAnEmptyBlockAndNoHint(): void {
		$prefill = $this->prefill(['subject-1' => ['subjectRef' => 'subject-1', 'displayName' => 'Ans de Vries']]);

		$values = $prefill->forSubject(subject: null, fields: $this->applicantFields());

		// Empty, and empty of keys: an "available" flag would say the visitor
		// is known, which is exactly what an anonymous form must not say.
		$this->assertSame([], $values);

	}//end testAnAnonymousVisitorGetsAnEmptyBlockAndNoHint()

	public function testPrefillNeverReadsAnotherIdentity(): void {
		$prefill = $this->prefill([
			'subject-1' => ['subjectRef' => 'subject-1', 'displayName' => 'Ans de Vries'],
			'subject-2' => ['subjectRef' => 'subject-2', 'displayName' => 'Iemand anders'],
		]);

		$values = $prefill->forSubject(subject: ['subjectRef' => 'subject-1'], fields: $this->applicantFields());

		$this->assertSame(['applicantName' => 'Ans de Vries'], array_intersect_key($values, ['applicantName' => true]));
		$this->assertNotContains('Iemand anders', $values);

	}//end testPrefillNeverReadsAnotherIdentity()

	public function testAReaderThatAnswersSomebodyElseIsIgnored(): void {
		// The reader is told to answer the wrong account: the service must
		// re-check the subjectRef rather than trust what came back.
		$accounts = $this->getMockBuilder(PortalAccountService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findBySubjectRef'])
			->getMock();
		$accounts->method('findBySubjectRef')->willReturn(['subjectRef' => 'subject-2', 'displayName' => 'Iemand anders']);

		$values = (new PortalApplicantPrefill($accounts))->forSubject(subject: ['subjectRef' => 'subject-1'], fields: $this->applicantFields());

		$this->assertSame([], $values);

	}//end testAReaderThatAnswersSomebodyElseIsIgnored()

	public function testOnlyTheApplicantFieldsAreEverFilled(): void {
		$prefill = $this->prefill(['subject-1' => ['subjectRef' => 'subject-1', 'displayName' => 'Ans de Vries', 'identityRef' => 'bsn-1', 'claims' => ['dossiq' => ['linkedRequesterId' => 'requester-77']]]]);

		$values = $prefill->forSubject(
			subject: ['subjectRef' => 'subject-1'],
			fields: [['name' => 'identityRef'], ['name' => 'claims'], ['name' => 'applicantName']]
		);

		$this->assertSame(['applicantName' => 'Ans de Vries'], $values);

	}//end testOnlyTheApplicantFieldsAreEverFilled()

	/**
	 * The applicant block of a form.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function applicantFields(): array {
		return [['name' => 'applicantName'], ['name' => 'applicantEmail'], ['name' => 'postcode']];
	}//end applicantFields()

	/**
	 * The service over a set of accounts.
	 *
	 * @param array<string, array<string, mixed>> $accounts The accounts by subjectRef.
	 *
	 * @return PortalApplicantPrefill
	 */
	private function prefill(array $accounts): PortalApplicantPrefill {
		$service = $this->getMockBuilder(PortalAccountService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findBySubjectRef'])
			->getMock();
		$service->method('findBySubjectRef')->willReturnCallback(
			static function (string $subjectRef) use ($accounts): ?array {
				return ($accounts[$subjectRef] ?? null);
			}
		);

		return new PortalApplicantPrefill($service);
	}//end prefill()

}//end class
