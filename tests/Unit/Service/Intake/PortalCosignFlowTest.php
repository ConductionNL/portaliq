<?php

/**
 * Portaliq Portal Cosign Flow Test
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

use DateTimeImmutable;
use OCA\Portaliq\Service\Intake\FormCosignMailer;
use OCA\Portaliq\Service\Intake\PortalCosign;
use OCA\Portaliq\Service\Intake\PortalCosignFlow;
use OCA\Portaliq\Service\Intake\QueuedSubmission;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * resident-identity-in-forms T04: at submit the request is held for the
 * co-signer the form names and the invitation goes out; a refusal and a lapse
 * tell the submitter at the address kept with the request.
 *
 * @covers \OCA\Portaliq\Service\Intake\PortalCosignFlow
 * @uses   \OCA\Portaliq\Service\Intake\QueuedSubmission
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class PortalCosignFlowTest extends TestCase {

	private const FIELDS = [
		['name' => 'email', 'type' => 'email'],
		['name' => 'partner', 'type' => 'email', 'cosigner' => true],
		['name' => 'partnerName', 'type' => 'string', 'cosignerName' => true],
	];

	private const ANSWERS = ['email' => 'sanne@example.nl', 'partner' => ' henk@example.nl ', 'partnerName' => ' Henk de Vries '];

	/**
	 * The co-sign core.
	 *
	 * @var PortalCosign&MockObject
	 */
	private PortalCosign $cosign;

	/**
	 * The mailer.
	 *
	 * @var FormCosignMailer&MockObject
	 */
	private FormCosignMailer $mailer;

	private PortalCosignFlow $flow;

	protected function setUp(): void {
		$this->cosign = $this->createMock(PortalCosign::class);
		$this->mailer = $this->createMock(FormCosignMailer::class);
		$l10n         = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->flow = new PortalCosignFlow(cosign: $this->cosign, mailer: $this->mailer, l10n: $l10n);
	}//end setUp()

	public function testRequiredAsksTheCore(): void {
		$this->cosign->expects($this->once())->method('required')->with(['cosign' => ['required' => true]])->willReturn(true);

		$this->assertTrue($this->flow->required(binding: ['cosign' => ['required' => true]]));
	}//end testRequiredAsksTheCore()

	public function testTheRequestIsHeldAndTheCoSignerInvited(): void {
		$now        = new DateTimeImmutable('2026-10-08T10:00:00+00:00');
		$submission = new QueuedSubmission(portal: 'gemeente-x', route: 'woo', answers: self::ANSWERS, subjectRef: 'bsn-sanne');
		$this->cosign->expects($this->once())
			->method('hold')
			->with($submission, 'henk@example.nl', 'Henk de Vries', 7, $now, 'sanne@example.nl')
			->willReturn(['reference' => 'AANVRAAG-1', 'state' => 'awaiting-cosign', 'code' => '7RQ-K2M', 'deadline' => '2026-10-15T10:00:00+00:00']);
		$this->mailer->expects($this->once())
			->method('invite')
			->with('henk@example.nl', '7RQ-K2M', '2026-10-15T10:00:00+00:00', 'Woo-verzoek', ['slug' => 'gemeente-x'])
			->willReturn(true);

		$held = $this->flow->holdAtSubmit(
			site: ['slug' => 'gemeente-x'],
			binding: ['cosign' => ['required' => true, 'deadlineDays' => 7]],
			render: ['fields' => self::FIELDS, 'formName' => 'Woo-verzoek'],
			submission: $submission,
			now: $now
		);

		$this->assertSame(
			[
				'ok' => true,
				'reference' => 'AANVRAAG-1',
				'state' => 'awaiting-cosign',
				'cosign' => ['state' => 'awaiting', 'name' => 'Henk de Vries', 'deadline' => '2026-10-15T10:00:00+00:00'],
				'invited' => true,
			],
			$held
		);
		$this->assertArrayNotHasKey('code', $held);
	}//end testTheRequestIsHeldAndTheCoSignerInvited()

	public function testAFormWithoutAValidCoSignerAddressNamesTheField(): void {
		$this->cosign->expects($this->never())->method('hold');
		$this->mailer->expects($this->never())->method('invite');
		$answers = ['partner' => 'not an address'];

		$held = $this->flow->holdAtSubmit(
			site: [],
			binding: ['cosign' => ['required' => true]],
			render: ['fields' => self::FIELDS],
			submission: new QueuedSubmission(portal: 'gemeente-x', route: 'woo', answers: $answers),
			now: new DateTimeImmutable()
		);

		$this->assertSame(['ok' => false, 'errors' => ['partner' => 'Fill in the e-mail address of the person who co-signs.']], $held);

		$none = $this->flow->holdAtSubmit(
			site: [],
			binding: [],
			render: ['fields' => [['name' => 'email', 'type' => 'email']]],
			submission: new QueuedSubmission(portal: 'gemeente-x', route: 'woo', answers: ['email' => 'sanne@example.nl']),
			now: new DateTimeImmutable()
		);
		$this->assertSame(['cosigner'], array_keys($none['errors']));
	}//end testAFormWithoutAValidCoSignerAddressNamesTheField()

	public function testNothingRecordedIsNoInvitation(): void {
		$this->cosign->method('hold')->willReturn(null);
		$this->mailer->expects($this->never())->method('invite');

		$held = $this->flow->holdAtSubmit(
			site: [],
			binding: ['cosign' => ['required' => true]],
			render: ['fields' => self::FIELDS],
			submission: new QueuedSubmission(portal: 'gemeente-x', route: 'woo', answers: self::ANSWERS),
			now: new DateTimeImmutable()
		);

		$this->assertSame(['ok' => false], $held);
	}//end testNothingRecordedIsNoInvitation()

	public function testARefusalTellsTheSubmitter(): void {
		$now = new DateTimeImmutable('2026-10-11T08:00:00+00:00');
		$this->cosign->expects($this->once())->method('refuse')->with('7RQ-K2M', 'bsn-henk', $now)->willReturn(
			['ok' => true, 'reason' => '', 'reference' => 'AANVRAAG-1', 'portal' => 'gemeente-x', 'cosign' => ['state' => 'refused', 'notify' => 'sanne@example.nl']]
		);
		$this->mailer->expects($this->once())->method('ended')->with('sanne@example.nl', 'AANVRAAG-1', 'refused', ['slug' => 'gemeente-x']);

		$this->assertSame(
			['ok' => true, 'reason' => '', 'reference' => 'AANVRAAG-1'],
			$this->flow->refuse(site: ['slug' => 'gemeente-x'], code: '7RQ-K2M', subjectRef: 'bsn-henk', now: $now)
		);
	}//end testARefusalTellsTheSubmitter()

	public function testAFailedRefusalOrNoAddressMailsNobody(): void {
		$this->cosign->method('refuse')->willReturnOnConsecutiveCalls(
			['ok' => false, 'reason' => 'same-subject', 'reference' => ''],
			['ok' => true, 'reason' => '', 'reference' => 'AANVRAAG-2', 'cosign' => ['state' => 'refused', 'notify' => ' ']]
		);
		$this->mailer->expects($this->never())->method('ended');

		$this->assertSame('same-subject', $this->flow->refuse(site: [], code: 'x', subjectRef: 'bsn-sanne', now: new DateTimeImmutable())['reason']);
		$this->assertTrue($this->flow->refuse(site: [], code: 'x', subjectRef: 'bsn-henk', now: new DateTimeImmutable())['ok']);
	}//end testAFailedRefusalOrNoAddressMailsNobody()

	public function testEachLapseTellsItsSubmitterAsExpired(): void {
		$now = new DateTimeImmutable('2026-10-23T00:00:00+00:00');
		$this->cosign->expects($this->once())->method('expire')->willReturnCallback(
			static function (DateTimeImmutable $at, ?callable $onLapse) use ($now): array {
				self::assertSame($now, $at);
				$onLapse(['reference' => 'AANVRAAG-1', 'portal' => 'gemeente-x', 'cosign' => ['state' => 'awaiting', 'notify' => 'sanne@example.nl']]);
				$onLapse(['reference' => 'AANVRAAG-2', 'portal' => 'gemeente-y', 'cosign' => []]);
				return ['AANVRAAG-1', 'AANVRAAG-2'];
			}
		);
		$this->mailer->expects($this->once())->method('ended')->with('sanne@example.nl', 'AANVRAAG-1', 'expired', ['slug' => 'gemeente-x']);

		$this->assertSame(['AANVRAAG-1', 'AANVRAAG-2'], $this->flow->lapse(now: $now));
	}//end testEachLapseTellsItsSubmitterAsExpired()

	public function testTheInviteeAndTheSubmitterAreTheirOwnFields(): void {
		$this->assertSame(
			['email' => 'henk@example.nl', 'field' => 'partner', 'name' => 'Henk de Vries'],
			PortalCosignFlow::invitee(fields: self::FIELDS, answers: self::ANSWERS)
		);
		$this->assertSame('sanne@example.nl', PortalCosignFlow::submitterAddress(fields: self::FIELDS, answers: self::ANSWERS));

		$reversed = [self::FIELDS[1], 'not a field', self::FIELDS[0]];
		$this->assertSame('sanne@example.nl', PortalCosignFlow::submitterAddress(fields: $reversed, answers: self::ANSWERS));
		$this->assertSame('', PortalCosignFlow::submitterAddress(fields: $reversed, answers: ['email' => ['x']]));
		$this->assertSame(['email' => '', 'field' => 'cosigner', 'name' => ''], PortalCosignFlow::invitee(fields: [], answers: self::ANSWERS));
	}//end testTheInviteeAndTheSubmitterAreTheirOwnFields()
}//end class
