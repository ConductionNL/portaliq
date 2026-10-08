<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Event\PortalAccountClaimRequestedEvent;
use OCA\Portaliq\Event\PortalAccountProvisionRequestedEvent;
use OCA\Portaliq\Listener\PortalAccountClaimListener;
use OCA\Portaliq\Listener\PortalAccountProvisionListener;
use OCA\Portaliq\Service\PortalAccountService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * portal-identity-space REQ-PIS-003: an app asks through a typed event and
 * reads the answer from the event's result slot. The app id comes from the
 * dispatching context, so a claim can never be written under another app's
 * name, and a failure is a word in the slot rather than an exception crossing
 * the app boundary.
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class PortalAccountEventListenerTest extends TestCase {

	public function testTheProvisionListenerAnswersTheSubjectRefAndStatus(): void {
		$accounts = $this->accounts();
		$accounts->method('provision')->willReturn(['subjectRef' => 'subject-1', 'isNew' => true, 'status' => 'pending']);
		$event = new PortalAccountProvisionRequestedEvent(appId: 'dossiq', audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1');

		(new PortalAccountProvisionListener($accounts, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertSame('subject-1', $event->getSubjectRef());
		$this->assertSame('pending', $event->getStatus());
		$this->assertSame('', $event->getRefusal());

	}//end testTheProvisionListenerAnswersTheSubjectRefAndStatus()

	public function testTheProvisionListenerRefusesADispatcherWithNoAppId(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->never())->method('provision');
		$event = new PortalAccountProvisionRequestedEvent(appId: '', audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1');

		(new PortalAccountProvisionListener($accounts, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertSame('unknown_app', $event->getRefusal());
		$this->assertSame('', $event->getSubjectRef());

	}//end testTheProvisionListenerRefusesADispatcherWithNoAppId()

	public function testAProvisionFailureIsARefusalNotAnException(): void {
		$accounts = $this->accounts();
		$accounts->method('provision')->willThrowException(new RuntimeException('OpenRegister is down'));
		$event = new PortalAccountProvisionRequestedEvent(appId: 'dossiq', audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1');

		(new PortalAccountProvisionListener($accounts, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertSame('unavailable', $event->getRefusal());

	}//end testAProvisionFailureIsARefusalNotAnException()

	public function testTheProvisionListenerStampsTheDispatchingAppAsTheProvisioner(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->once())
			->method('provision')
			->with(
				$this->equalTo('client'),
				$this->equalTo('gemeente-x'),
				$this->equalTo('digid'),
				$this->equalTo('bsn-1'),
				$this->equalTo(''),
				$this->equalTo(false),
				$this->equalTo('dossiq'),
				$this->equalTo('')
			)
			->willReturn(['subjectRef' => 'subject-1', 'isNew' => true, 'status' => 'pending']);
		$event = new PortalAccountProvisionRequestedEvent(appId: 'dossiq', audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1');

		(new PortalAccountProvisionListener($accounts, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertSame('subject-1', $event->getSubjectRef());

	}//end testTheProvisionListenerStampsTheDispatchingAppAsTheProvisioner()

	public function testTheClaimListenerWritesUnderTheDispatchingAppAndAnswersOk(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->once())
			->method('claim')
			->with($this->equalTo('subject-1'), $this->equalTo('dossiq'), $this->equalTo('linkedRequesterId'), $this->equalTo('requester-77'))
			->willReturn(true);
		$event = new PortalAccountClaimRequestedEvent(appId: 'dossiq', subjectRef: 'subject-1', claimName: 'linkedRequesterId', value: 'requester-77');

		(new PortalAccountClaimListener($accounts, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertSame('ok', $event->getResult());

	}//end testTheClaimListenerWritesUnderTheDispatchingAppAndAnswersOk()

	public function testARefusedClaimSaysSoInTheResultSlot(): void {
		$accounts = $this->accounts();
		$accounts->method('claim')->willReturn(false);
		$event = new PortalAccountClaimRequestedEvent(appId: 'dossiq', subjectRef: 'subject-1', claimName: 'linkedRequesterId', value: 'requester-77');

		(new PortalAccountClaimListener($accounts, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertSame('refused', $event->getResult());

	}//end testARefusedClaimSaysSoInTheResultSlot()

	public function testAForeignEventIsIgnoredEntirely(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->never())->method('claim');
		$accounts->expects($this->never())->method('provision');

		(new PortalAccountClaimListener($accounts, $this->createMock(LoggerInterface::class)))->handle(new Event());
		(new PortalAccountProvisionListener($accounts, $this->createMock(LoggerInterface::class)))->handle(new Event());

		$this->addToAssertionCount(1);

	}//end testAForeignEventIsIgnoredEntirely()

	/**
	 * A double that can only answer methods the real service actually has.
	 *
	 * @return PortalAccountService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function accounts(): PortalAccountService {
		return $this->getMockBuilder(PortalAccountService::class)
			->disableOriginalConstructor()
			->onlyMethods(['provision', 'claim'])
			->getMock();
	}//end accounts()

}//end class
