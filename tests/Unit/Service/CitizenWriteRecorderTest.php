<?php

/**
 * Tests for the record on the case, the audit row and the citizen write event.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Event\PortalClientWithdrawalEvent;
use OCA\Portaliq\Event\PortalClientWriteEvent;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\CitizenWriteRecorder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Every citizen write leaves the identity, the mandate and both answers on the
 * case, an audit row, and exactly one event.
 *
 * @covers \OCA\Portaliq\Service\CitizenWriteRecorder
 * @covers \OCA\Portaliq\Event\PortalClientWriteEvent
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenWriteRecorderTest extends TestCase {
	private const SUBJECT = [
		'subjectRef' => 'bsn-hash-1',
		'audience' => 'client',
		'organisation' => 'gemeente-1',
		'trust' => 'high',
		'jti' => 'jti-1',
	];

	private const ACTION = ['id' => 'amend-request', 'minTrust' => 'substantial'];

	/**
	 * The record names the identity and the mandate it acted under, and keeps
	 * the answer as it was beside the answer as it is.
	 */
	public function testTheRecordNamesTheIdentityTheMandateAndBothAnswers(): void {
		$record = $this->recorder()->buildRecord(
			act: PortalClientWriteEvent::ACT_AMENDMENT,
			subject: self::SUBJECT,
			action: self::ACTION,
			changes: ['omschrijving' => ['from' => 'dakkapel', 'to' => 'dakkapel aan de achterzijde']],
			occurredAt: '2026-09-14T10:00:00+00:00'
		);

		$this->assertSame('amendment', $record['act']);
		$this->assertSame('bsn-hash-1', $record['identity']['subjectRef']);
		$this->assertSame('client', $record['identity']['audience']);
		$this->assertSame('high', $record['identity']['trust']);
		$this->assertSame('jti-1', $record['identity']['jti']);
		$this->assertSame('amend-request', $record['mandate']['action']);
		$this->assertSame('substantial', $record['mandate']['minTrust']);
		$this->assertSame('dakkapel', $record['changes']['omschrijving']['from']);
		$this->assertSame('dakkapel aan de achterzijde', $record['changes']['omschrijving']['to']);
		$this->assertSame('2026-09-14T10:00:00+00:00', $record['at']);
	}//end testTheRecordNamesTheIdentityTheMandateAndBothAnswers()

	/**
	 * The history keeps both answers: an amendment is appended to whatever the
	 * case already carries, never written over it.
	 */
	public function testAnAmendmentIsAppendedNotWrittenOver(): void {
		$existing = [['act' => 'amendment', 'at' => '2026-09-13T09:00:00+00:00']];

		$history = $this->recorder()->append(existing: $existing, record: ['act' => 'amendment', 'at' => 'later']);

		$this->assertCount(2, $history);
		$this->assertSame('2026-09-13T09:00:00+00:00', $history[0]['at']);
		$this->assertSame('later', $history[1]['at']);
	}//end testAnAmendmentIsAppendedNotWrittenOver()

	/**
	 * A record list that is not a list, or that holds junk, never makes the
	 * whole history unreadable: the junk goes, the new record lands.
	 */
	public function testAMalformedHistoryIsRepairedRatherThanLost(): void {
		$history = $this->recorder()->append(existing: 'ooit gewijzigd', record: ['act' => 'document']);
		$this->assertSame([['act' => 'document']], $history);

		$mixed = $this->recorder()->append(existing: [['act' => 'amendment'], 'rommel'], record: ['act' => 'document']);
		$this->assertCount(2, $mixed);
		$this->assertSame('amendment', $mixed[0]['act']);
		$this->assertSame('document', $mixed[1]['act']);
	}//end testAMalformedHistoryIsRepairedRatherThanLost()

	/**
	 * The rule fires once, with the case and the identity. One dispatch, not
	 * two, so a bound rule cannot run twice on one act.
	 */
	public function testTheEventIsRaisedOnceWithTheCaseAndTheIdentity(): void {
		$raised = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use (&$raised): void {
				$raised[] = $event;
			}
		);

		$this->recorder(dispatcher: $dispatcher)->announce(
			register: 'zaken',
			schema: 'zaak',
			caseId: 'zaak-1',
			act: PortalClientWriteEvent::ACT_AMENDMENT,
			fields: ['omschrijving'],
			subject: self::SUBJECT,
			action: self::ACTION,
			occurredAt: '2026-09-14T10:00:00+00:00'
		);

		$this->assertCount(1, $raised);
		$event = $raised[0];
		$this->assertInstanceOf(PortalClientWriteEvent::class, $event);
		$this->assertSame('portal.write.client', PortalClientWriteEvent::NAME);
		$this->assertSame('zaken', $event->getRegister());
		$this->assertSame('zaak', $event->getSchema());
		$this->assertSame('zaak-1', $event->getCaseId());
		$this->assertSame('amendment', $event->getAct());
		$this->assertSame(['omschrijving'], $event->getFields());
		$this->assertSame('bsn-hash-1', $event->getIdentity()['subjectRef']);
		$this->assertSame('amend-request', $event->getMandate()['action']);
		$this->assertSame('2026-09-14T10:00:00+00:00', $event->getOccurredAt());
	}//end testTheEventIsRaisedOnceWithTheCaseAndTheIdentity()

	/**
	 * The same act also lands in the append-only audit trail, naming the
	 * identity, the case and the session it was made in.
	 */
	public function testTheActIsRecordedInTheAuditTrail(): void {
		$captured = [];
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->once())->method('record')->willReturnCallback(
			function (
				string $verb,
				string $subjectRef,
				string $organisation,
				string $register,
				string $schema,
				string $id,
				string $jti = '',
			) use (&$captured): void {
				$captured = compact('verb', 'subjectRef', 'organisation', 'register', 'schema', 'id', 'jti');
			}
		);

		$this->recorder(auditor: $auditor)->announce(
			register: 'zaken',
			schema: 'zaak',
			caseId: 'zaak-1',
			act: PortalClientWriteEvent::ACT_DOCUMENT,
			fields: ['aanvulling.pdf'],
			subject: self::SUBJECT,
			action: self::ACTION,
			occurredAt: '2026-09-14T10:00:00+00:00'
		);

		$this->assertSame('bsn-hash-1', $captured['subjectRef']);
		$this->assertSame('gemeente-1', $captured['organisation']);
		$this->assertSame('zaak-1', $captured['id']);
		$this->assertSame('jti-1', $captured['jti']);
	}//end testTheActIsRecordedInTheAuditTrail()

	/**
	 * 🔴 A WITHDRAWAL IS ANNOUNCED AS ITS OWN EVENT, AND NOTHING EXECUTED THAT.
	 *
	 * `announceWithdrawal()` had zero references in any test. Its whole reason
	 * for existing is in its own docblock: the withdrawal is raised as its own
	 * event, never as a write, so that a rule bound to a CITIZEN withdrawing
	 * their case does not also fire when a handler sets the same status
	 * internally. This is the path that tells the two apart.
	 *
	 * If it stopped dispatching, every automation bound to a citizen withdrawal
	 * would quietly stop firing, and the case would still withdraw, so nothing
	 * on screen would look wrong.
	 *
	 * @return void
	 */
	public function testAWithdrawalIsAnnouncedAsItsOwnEvent(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatched = null;
		$dispatcher->expects($this->once())
			->method('dispatchTyped')
			->willReturnCallback(
				static function (Event $event) use (&$dispatched): void {
					$dispatched = $event;
				}
			);

		$this->recorder(dispatcher: $dispatcher)->announceWithdrawal(
			register: 'reg-1',
			schema: 'zaak',
			caseId: 'case-1',
			status: 'ingetrokken',
			reason: 'niet meer nodig',
			subject: ['subjectRef' => 'burger-1', 'organisation' => 'gemeente-x', 'jti' => 'jti-1'],
			action: [],
			occurredAt: '2026-09-18T10:00:00+00:00'
		);

		$this->assertInstanceOf(PortalClientWithdrawalEvent::class, $dispatched);
		$this->assertNotInstanceOf(
			PortalClientWriteEvent::class,
			$dispatched,
			'A withdrawal announced as an ordinary write would fire every rule bound to a write.'
		);
	}//end testAWithdrawalIsAnnouncedAsItsOwnEvent()

	/**
	 * What the applicant said travels with the event.
	 *
	 * The reason is the whole point of asking for one: a withdrawal whose
	 * reason did not reach the listeners would be recorded and then acted on
	 * without it.
	 *
	 * @return void
	 */
	public function testTheWithdrawalCarriesWhatWasSaidAndWhereItLanded(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatched = null;
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use (&$dispatched): void {
				$dispatched = $event;
			}
		);

		$this->recorder(dispatcher: $dispatcher)->announceWithdrawal(
			register: 'reg-1',
			schema: 'zaak',
			caseId: 'case-1',
			status: 'ingetrokken',
			reason: 'niet meer nodig',
			subject: ['subjectRef' => 'burger-1', 'organisation' => 'gemeente-x', 'jti' => 'jti-1'],
			action: [],
			occurredAt: '2026-09-18T10:00:00+00:00'
		);

		$this->assertSame('niet meer nodig', $dispatched->getReason());
		$this->assertSame('ingetrokken', $dispatched->getStatus());
		$this->assertSame('case-1', $dispatched->getCaseId());
		$this->assertSame('2026-09-18T10:00:00+00:00', $dispatched->getOccurredAt());
	}//end testTheWithdrawalCarriesWhatWasSaidAndWhereItLanded()

	/**
	 * 🔑 THE WITHDRAWAL IS AUDITED, AND THE AUDIT NAMES THE CITIZEN.
	 *
	 * The event tells automation; the audit trail tells a person afterwards who
	 * withdrew the case. Dispatching without recording would leave a case that
	 * changed state with nobody's name against it.
	 *
	 * @return void
	 */
	public function testTheWithdrawalIsAuditedAgainstTheCitizen(): void {
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->once())
			->method('record')
			->with(
				$this->equalTo('update'),
				$this->equalTo('burger-1'),
				$this->equalTo('gemeente-x'),
				$this->equalTo('reg-1'),
				$this->equalTo('zaak'),
				$this->equalTo('case-1'),
				$this->equalTo('jti-1')
			);

		$this->recorder(auditor: $auditor)->announceWithdrawal(
			register: 'reg-1',
			schema: 'zaak',
			caseId: 'case-1',
			status: 'ingetrokken',
			reason: '',
			subject: ['subjectRef' => 'burger-1', 'organisation' => 'gemeente-x', 'jti' => 'jti-1'],
			action: [],
			occurredAt: '2026-09-18T10:00:00+00:00'
		);
	}//end testTheWithdrawalIsAuditedAgainstTheCitizen()

	/**
	 * Build a recorder over doubles.
	 *
	 * @param AuditTrailService|null $auditor The audit trail double.
	 * @param IEventDispatcher|null $dispatcher The dispatcher double.
	 */
	private function recorder(?AuditTrailService $auditor = null, ?IEventDispatcher $dispatcher = null): CitizenWriteRecorder {
		return new CitizenWriteRecorder(
			($auditor ?? $this->createMock(AuditTrailService::class)),
			($dispatcher ?? $this->createMock(IEventDispatcher::class))
		);
	}//end recorder()

	/**
	 * portal-visibility-follows-the-party-tree REQ-PTV-006: a write made for an
	 * entity below the mandated one records both the entity and the mandate,
	 * and an ordinary write records neither, so the two are told apart in the
	 * record rather than guessed at later.
	 *
	 * @spec openspec/changes/portal-visibility-follows-the-party-tree/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	public function testAWriteForAnEntityBelowTheMandateRecordsBoth(): void {
		$recorder = new CitizenWriteRecorder(
			$this->createMock(\OCA\Portaliq\Service\AuditTrailService::class),
			$this->createMock(\OCP\EventDispatcher\IEventDispatcher::class)
		);
		$subject = array_merge(self::SUBJECT, ['actingForEntity' => 'kvk-subsidiary', 'actingUnderMandate' => 'mandate-1']);

		$mandate = $recorder->mandate(action: self::ACTION, subject: $subject);

		$this->assertSame('kvk-subsidiary', $mandate['actingFor']);
		$this->assertSame('mandate-1', $mandate['mandate']);

	}//end testAWriteForAnEntityBelowTheMandateRecordsBoth()

	public function testAnOrdinaryWriteNamesNoEntityAndNoMandate(): void {
		$recorder = new CitizenWriteRecorder(
			$this->createMock(\OCA\Portaliq\Service\AuditTrailService::class),
			$this->createMock(\OCP\EventDispatcher\IEventDispatcher::class)
		);

		$mandate = $recorder->mandate(action: self::ACTION, subject: self::SUBJECT);

		$this->assertArrayNotHasKey('actingFor', $mandate);
		$this->assertArrayNotHasKey('mandate', $mandate);

	}//end testAnOrdinaryWriteNamesNoEntityAndNoMandate()
}//end class
