<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Tasks;

use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalTaskGateway;
use OCA\Portaliq\Service\Tasks\PartnerAskService;
use PHPUnit\Framework\TestCase;

/**
 * partner-tasks-in-the-portal REQ-PTP-002: a handler asks an outside partner
 * for something from the case. An unknown partner is pre-provisioned from a
 * KvK number and an address, the due date and upload rules are frozen onto the
 * task, and a subjectRef nobody answers to raises nothing.
 *
 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
 */
class PartnerAskServiceTest extends TestCase {

	/**
	 * What the gateway was asked to raise.
	 *
	 * @var array<string, mixed>
	 */
	private array $raised = [];

	protected function setUp(): void {
		$this->raised = [];

	}//end setUp()

	public function testAnUnknownPartnerIsPreProvisionedAndTheTaskIsAddressedToIt(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->once())
			->method('provision')
			->with(
				$this->equalTo('partner'),
				$this->equalTo('gemeente-x'),
				$this->equalTo('eherkenning'),
				$this->equalTo('12345678'),
				$this->equalTo('welstand@example.org'),
				$this->equalTo(false),
				$this->equalTo('handler-anna'),
				$this->equalTo('Welstandscommissie')
			)
			->willReturn(['subjectRef' => 'partner-subject', 'isNew' => true, 'status' => 'pending']);

		$asked = $this->service(accounts: $accounts)->ask(
			handler: $this->handler(),
			partner: ['kvk' => '12345678', 'email' => 'welstand@example.org', 'name' => 'Welstandscommissie'],
			ask: $this->ask()
		);

		$this->assertTrue($asked['provisioned']);
		$this->assertSame('partner-subject', $asked['subjectRef']);
		$this->assertSame('partner-subject', $this->raised['subjectRef']);

	}//end testAnUnknownPartnerIsPreProvisionedAndTheTaskIsAddressedToIt()

	public function testTheDueDateAndUploadRulesAreFrozenOnTheTask(): void {
		$this->service()->ask(handler: $this->handler(), partner: ['subjectRef' => 'partner-subject'], ask: $this->ask());

		$this->assertSame('2026-10-02T09:00:00+00:00', $this->raised['task']['dueAt']);
		$this->assertSame([['mimeType' => 'application/pdf', 'required' => true]], $this->raised['task']['uploadRules']);
		$this->assertSame('zaak-1', $this->raised['task']['object']['id']);

	}//end testTheDueDateAndUploadRulesAreFrozenOnTheTask()

	public function testAKnownPartnerIsNotProvisionedAgain(): void {
		$accounts = $this->accounts();
		$accounts->method('findBySubjectRef')->willReturn(['subjectRef' => 'partner-subject', 'audience' => 'partner']);
		$accounts->expects($this->never())->method('provision');

		$asked = $this->service(accounts: $accounts)->ask(
			handler: $this->handler(),
			partner: ['subjectRef' => 'partner-subject'],
			ask: $this->ask()
		);

		$this->assertFalse($asked['provisioned']);

	}//end testAKnownPartnerIsNotProvisionedAgain()

	public function testASubjectRefNobodyAnswersToRaisesNothing(): void {
		$accounts = $this->accounts();
		$accounts->method('findBySubjectRef')->willReturn(null);
		$accounts->expects($this->never())->method('provision');

		$asked = $this->service(accounts: $accounts)->ask(
			handler: $this->handler(),
			partner: ['subjectRef' => 'a-reference-somebody-typed'],
			ask: $this->ask()
		);

		$this->assertNull($asked);
		$this->assertSame([], $this->raised);

	}//end testASubjectRefNobodyAnswersToRaisesNothing()

	public function testAnAskWithNoTitleOrNoCaseRaisesNothing(): void {
		$service = $this->service();

		$noTitle = $this->ask();
		$noTitle['title'] = '  ';
		$noCase = $this->ask();
		$noCase['caseId'] = '';

		$this->assertNull($service->ask(handler: $this->handler(), partner: ['subjectRef' => 'partner-subject'], ask: $noTitle));
		$this->assertNull($service->ask(handler: $this->handler(), partner: ['subjectRef' => 'partner-subject'], ask: $noCase));
		$this->assertSame([], $this->raised);

	}//end testAnAskWithNoTitleOrNoCaseRaisesNothing()

	public function testAPartnerWithNeitherReferenceNorAddressRaisesNothing(): void {
		$asked = $this->service()->ask(handler: $this->handler(), partner: [], ask: $this->ask());

		$this->assertNull($asked);
		$this->assertSame([], $this->raised);

	}//end testAPartnerWithNeitherReferenceNorAddressRaisesNothing()

	public function testAnEngineRefusalIsNotReportedAsATask(): void {
		$tasks = $this->getMockBuilder(PortalTaskGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['createTask'])
			->getMock();
		$tasks->method('createTask')->willReturn(['status' => 409, 'body' => ['error' => 'conflict']]);

		$service = new PartnerAskService($this->accountsAnsweringAnyone(), $tasks);

		$this->assertNull($service->ask(handler: $this->handler(), partner: ['subjectRef' => 'partner-subject'], ask: $this->ask()));

	}//end testAnEngineRefusalIsNotReportedAsATask()

	/**
	 * The handler's subject shape.
	 *
	 * @return array<string, mixed>
	 */
	private function handler(): array {
		return ['uid' => 'handler-anna', 'subjectRef' => 'handler-anna', 'organisation' => 'gemeente-x', 'audience' => 'handler'];
	}//end handler()

	/**
	 * The ask itself.
	 *
	 * @return array<string, mixed>
	 */
	private function ask(): array {
		return [
			'title' => 'Advies welstand',
			'description' => 'Graag uw oordeel over de gevelwijziging.',
			'dueAt' => '2026-10-02T09:00:00+00:00',
			'uploadRules' => [['mimeType' => 'application/pdf', 'required' => true]],
			'register' => 'dossiq',
			'schema' => 'zaak',
			'caseId' => 'zaak-1',
		];
	}//end ask()

	/**
	 * An account double.
	 *
	 * @return PortalAccountService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function accounts(): PortalAccountService {
		return $this->getMockBuilder(PortalAccountService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findBySubjectRef', 'provision'])
			->getMock();
	}//end accounts()

	/**
	 * An account double that knows every subjectRef it is asked about.
	 *
	 * @return PortalAccountService
	 */
	private function accountsAnsweringAnyone(): PortalAccountService {
		$accounts = $this->accounts();
		$accounts->method('findBySubjectRef')->willReturnCallback(
			static fn (string $subjectRef): array => ['subjectRef' => $subjectRef, 'audience' => 'partner']
		);
		$accounts->method('provision')->willReturn(['subjectRef' => 'partner-subject', 'isNew' => true, 'status' => 'pending']);

		return $accounts;
	}//end accountsAnsweringAnyone()

	/**
	 * The service over a gateway that records what it was asked to raise.
	 *
	 * @param PortalAccountService|null $accounts An account double, or the default.
	 *
	 * @return PartnerAskService
	 */
	private function service(?PortalAccountService $accounts = null): PartnerAskService {
		$tasks = $this->getMockBuilder(PortalTaskGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['createTask'])
			->getMock();
		$tasks->method('createTask')->willReturnCallback(
			function (array $handler, string $subjectRef, array $task): array {
				$this->raised = ['handler' => $handler, 'subjectRef' => $subjectRef, 'task' => $task];
				return ['status' => 201, 'body' => ['uuid' => 'task-1']];
			}
		);

		return new PartnerAskService(($accounts ?? $this->accountsAnsweringAnyone()), $tasks);
	}//end service()

}//end class
