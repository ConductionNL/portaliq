<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Tasks;

use OCA\Portaliq\Service\AccountProvisionRequest;
use OCA\Portaliq\Service\PortalAccountCreation;
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
		$creation = $this->creation();
		$creation->expects($this->once())
			->method('provision')
			->with(
				$this->equalTo(
					new AccountProvisionRequest(
						audience: 'partner',
						organisation: 'gemeente-x',
						verifiedEmail: false,
						identityType: 'eherkenning',
						identityRef: '12345678',
						email: 'welstand@example.org',
						provisionedBy: 'handler-anna',
						displayName: 'Welstandscommissie'
					)
				)
			)
			->willReturn(['subjectRef' => 'partner-subject', 'isNew' => true, 'status' => 'pending']);

		$asked = $this->service(accounts: $accounts, creation: $creation)->ask(
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
		$creation = $this->creation();
		$creation->expects($this->never())->method('provision');

		$asked = $this->service(accounts: $accounts, creation: $creation)->ask(
			handler: $this->handler(),
			partner: ['subjectRef' => 'partner-subject'],
			ask: $this->ask()
		);

		$this->assertFalse($asked['provisioned']);

	}//end testAKnownPartnerIsNotProvisionedAgain()

	public function testASubjectRefNobodyAnswersToRaisesNothing(): void {
		$accounts = $this->accounts();
		$accounts->method('findBySubjectRef')->willReturn(null);
		$creation = $this->creation();
		$creation->expects($this->never())->method('provision');

		$asked = $this->service(accounts: $accounts, creation: $creation)->ask(
			handler: $this->handler(),
			partner: ['subjectRef' => 'a-reference-somebody-typed'],
			ask: $this->ask()
		);

		$this->assertNull($asked);
		$this->assertSame([], $this->raised);

	}//end testASubjectRefNobodyAnswersToRaisesNothing()

	/**
	 * A withdrawn (void) partner is refused as an unknown one is: nothing is raised.
	 *
	 * @return void
	 */
	public function testAWithdrawnPartnerIsRefusedAsAnUnknownOneIs(): void {
		$accounts = $this->accounts();
		$accounts->method('findBySubjectRef')->willReturn(
			['id' => 'account-void', 'subjectRef' => 'partner-subject', 'audience' => 'partner', 'status' => 'void']
		);
		$creation = $this->creation();
		$creation->expects($this->never())->method('provision');

		$asked = $this->service(accounts: $accounts, creation: $creation)->ask(
			handler: $this->handler(),
			partner: ['subjectRef' => 'partner-subject'],
			ask: $this->ask()
		);

		$this->assertNull(actual: $asked);
		$this->assertSame(expected: [], actual: $this->raised);

	}//end testAWithdrawnPartnerIsRefusedAsAnUnknownOneIs()

	/**
	 * A KvK number that provisioning matches to a withdrawn account raises nothing.
	 *
	 * @return void
	 */
	public function testAKvkNumberThatFindsAWithdrawnAccountRaisesNothing(): void {
		$creation = $this->creation();
		$creation->method('provision')->willReturn(['subjectRef' => 'partner-void', 'isNew' => false, 'status' => 'void']);

		$asked = $this->service(creation: $creation)->ask(
			handler: $this->handler(),
			partner: ['kvk' => '12345678', 'email' => 'info@partner.example'],
			ask: $this->ask()
		);

		$this->assertNull(actual: $asked);
		$this->assertSame(expected: [], actual: $this->raised);

	}//end testAKvkNumberThatFindsAWithdrawnAccountRaisesNothing()

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

		$service = new PartnerAskService($this->accountsAnsweringAnyone(), $tasks, $this->creationAnsweringAnyone());

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
			->onlyMethods(['findBySubjectRef'])
			->getMock();
	}//end accounts()

	/**
	 * An account creation double.
	 *
	 * @return PortalAccountCreation&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function creation(): PortalAccountCreation {
		return $this->getMockBuilder(PortalAccountCreation::class)
			->disableOriginalConstructor()
			->onlyMethods(['provision'])
			->getMock();
	}//end creation()

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

		return $accounts;
	}//end accountsAnsweringAnyone()

	/**
	 * An account creation double that provisions every partner it is asked to.
	 *
	 * @return PortalAccountCreation
	 */
	private function creationAnsweringAnyone(): PortalAccountCreation {
		$creation = $this->creation();
		$creation->method('provision')->willReturn(['subjectRef' => 'partner-subject', 'isNew' => true, 'status' => 'pending']);

		return $creation;
	}//end creationAnsweringAnyone()

	/**
	 * The service over a gateway that records what it was asked to raise.
	 *
	 * @param PortalAccountService|null  $accounts An account double, or the default.
	 * @param PortalAccountCreation|null $creation An account creation double, or the default.
	 *
	 * @return PartnerAskService
	 */
	private function service(?PortalAccountService $accounts = null, ?PortalAccountCreation $creation = null): PartnerAskService {
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

		return new PartnerAskService(($accounts ?? $this->accountsAnsweringAnyone()), $tasks, ($creation ?? $this->creationAnsweringAnyone()));
	}//end service()

	/**
	 * Every ask and partner is raised, provisioned or refused exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryAskIsAsRecorded(): void {
		$calls    = [];
		$accounts = $this->accounts();
		$accounts->method('findBySubjectRef')->willReturnCallback(
			static function (string $subjectRef) use (&$calls): ?array {
				$calls[] = 'find ' . $subjectRef;
				return match ($subjectRef) {
					'known' => ['subjectRef' => 'known', 'status' => 'active'],
					'void' => ['subjectRef' => 'void', 'status' => PortalAccountService::STATUS_VOID],
					default => null,
				};
			}
		);
		$creation = $this->creation();
		$creation->method('provision')->willReturnCallback(
			static function (AccountProvisionRequest $request) use (&$calls): ?array {
				$args    = [
					$request->audience,
					$request->organisation,
					$request->identityType,
					$request->identityRef,
					$request->email,
					$request->verifiedEmail,
					$request->provisionedBy,
					$request->displayName,
				];
				$calls[] = 'provision ' . json_encode($args);
				return match ($args[3] . '|' . $args[4]) {
					'|only@mail.example' => ['subjectRef' => 'mail-only', 'isNew' => false, 'status' => 'pending'],
					'99999999|' => ['subjectRef' => 'gone', 'isNew' => true, 'status' => PortalAccountService::STATUS_VOID],
					'00000000|' => null,
					default => ['subjectRef' => 'new-one', 'isNew' => true, 'status' => 'pending'],
				};
			}
		);
		$tasks = $this->getMockBuilder(PortalTaskGateway::class)->disableOriginalConstructor()->onlyMethods(['createTask'])->getMock();
		$tasks->method('createTask')->willReturnCallback(
			static function (array $handler, string $subjectRef, array $task) use (&$calls): ?array {
				$calls[] = 'raise ' . $subjectRef . ' ' . json_encode($task);
				return match ($task['title']) {
					'Refused' => ['status' => 422, 'body' => ['error' => 'x']],
					'Lost' => null,
					'Bare' => ['status' => 200],
					default => ['status' => 201, 'body' => ['uuid' => 't']],
				};
			}
		);
		$service = new PartnerAskService($accounts, $tasks, $creation);
		$handler = ['uid' => 'h-uid', 'subjectRef' => 'h-ref', 'organisation' => 'org'];
		$ask     = ['title' => ' Advies ', 'description' => 'D', 'dueAt' => 'soon', 'uploadRules' => 'x', 'register' => 'r', 'schema' => 's', 'caseId' => 'c-1'];

		$results = [
			$service->ask(handler: $handler, partner: ['subjectRef' => 'known'], ask: $ask),
			$service->ask(handler: $handler, partner: ['subjectRef' => 'void'], ask: $ask),
			$service->ask(handler: $handler, partner: ['subjectRef' => 'nobody', 'kvk' => '12345678'], ask: $ask),
			$service->ask(handler: $handler, partner: ['kvk' => ' 12345678 ', 'email' => ' a@b.example ', 'name' => 'Partner'], ask: $ask),
			$service->ask(handler: ['subjectRef' => 'h-ref'], partner: ['email' => 'only@mail.example'], ask: ['title' => 'T', 'caseId' => 'c']),
			$service->ask(handler: [], partner: ['kvk' => '99999999'], ask: $ask),
			$service->ask(handler: $handler, partner: ['kvk' => '00000000'], ask: $ask),
			$service->ask(handler: $handler, partner: ['kvk' => ' ', 'email' => ' '], ask: $ask),
			$service->ask(handler: $handler, partner: ['subjectRef' => 'known'], ask: ['title' => ' ', 'caseId' => 'c']),
			$service->ask(handler: $handler, partner: ['subjectRef' => 'known'], ask: ['title' => 'T']),
			$service->ask(handler: $handler, partner: ['subjectRef' => 'known'], ask: ['title' => 'Refused', 'caseId' => 'c']),
			$service->ask(handler: $handler, partner: ['subjectRef' => 'known'], ask: ['title' => 'Lost', 'caseId' => 'c']),
			$service->ask(handler: $handler, partner: ['subjectRef' => 'known'], ask: ['title' => 'Bare', 'caseId' => 'c']),
		];

		$this->assertSame(
			'[{"subjectRef":"known","provisioned":false,"task":{"uuid":"t"}},null,null,{"subjectRef":"new-one","provisioned":true,"task":{"uuid":"t"}},{"subjectRef":"mail-only","provisioned":false,"task":{"uuid":"t"}},null,null,null,null,null,null,null,{"subjectRef":"known","provisioned":false,"task":[]}]',
			json_encode($results)
		);
		$this->assertSame(
			[
				'find known',
				'raise known {"title":"Advies","description":"D","dueAt":"soon","uploadRules":["x"],"object":{"register":"r","schema":"s","id":"c-1"},"audience":"partner","raisedBy":"h-ref"}',
				'find void',
				'find nobody',
				'provision ["partner","org","eherkenning","12345678","a@b.example",false,"h-uid","Partner"]',
				'raise new-one {"title":"Advies","description":"D","dueAt":"soon","uploadRules":["x"],"object":{"register":"r","schema":"s","id":"c-1"},"audience":"partner","raisedBy":"h-ref"}',
				'provision ["partner","","","","only@mail.example",false,"h-ref",""]',
				'raise mail-only {"title":"T","description":"","dueAt":"","uploadRules":[],"object":{"register":"","schema":"","id":"c"},"audience":"partner","raisedBy":"h-ref"}',
				'provision ["partner","","eherkenning","99999999","",false,"",""]',
				'provision ["partner","org","eherkenning","00000000","",false,"h-uid",""]',
				'find known',
				'raise known {"title":"Refused","description":"","dueAt":"","uploadRules":[],"object":{"register":"","schema":"","id":"c"},"audience":"partner","raisedBy":"h-ref"}',
				'find known',
				'raise known {"title":"Lost","description":"","dueAt":"","uploadRules":[],"object":{"register":"","schema":"","id":"c"},"audience":"partner","raisedBy":"h-ref"}',
				'find known',
				'raise known {"title":"Bare","description":"","dueAt":"","uploadRules":[],"object":{"register":"","schema":"","id":"c"},"audience":"partner","raisedBy":"h-ref"}',
			],
			array_map(static fn (string $call): string => str_replace('\\/', '/', $call), $calls)
		);
	}//end testEveryAskIsAsRecorded()

}//end class
