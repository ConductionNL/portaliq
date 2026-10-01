<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * portal-intake-form-as-an-object REQ-PIFO-005: the citizen gets a reference
 * without waiting for the case, and the page behind that reference reads the
 * submission's real state, a failed create included. Nothing here says a case
 * exists before one does.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalIntakeQueueTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	public function testASubmissionIsAcknowledgedWithAReferenceAndNoCase(): void {
		$queue = $this->queue();

		$accepted = $queue->accept(portal: 'gemeente-x', route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB']);

		$this->assertNotNull($accepted);
		$this->assertSame('queued', $accepted['state']);
		$this->assertStringStartsWith('AANVRAAG-', $accepted['reference']);
		$this->assertSame('', (string)($this->storedRows('portalIntakeSubmission')[0]['caseId'] ?? ''));

	}//end testASubmissionIsAcknowledgedWithAReferenceAndNoCase()

	public function testTheReferencePageSaysQueuedUntilTheCaseExists(): void {
		$queue = $this->queue();
		$accepted = $queue->accept(portal: 'gemeente-x', route: 'aanvragen/verhuizing', answers: []);

		$status = $queue->status(reference: $accepted['reference'], portal: 'gemeente-x');

		$this->assertSame('queued', $status['state']);
		$this->assertSame('', $status['caseId']);

	}//end testTheReferencePageSaysQueuedUntilTheCaseExists()

	public function testARegisteredSubmissionNamesItsCase(): void {
		$queue = $this->queue();
		$accepted = $queue->accept(portal: 'gemeente-x', route: 'aanvragen/verhuizing', answers: []);
		$queue->markRegistered(submission: $this->storedRows('portalIntakeSubmission')[0], caseId: 'zaak-1');

		$status = $queue->status(reference: $accepted['reference'], portal: 'gemeente-x');

		$this->assertSame('registered', $status['state']);
		$this->assertSame('zaak-1', $status['caseId']);

	}//end testARegisteredSubmissionNamesItsCase()

	public function testAFailedCreateIsVisibleNotSilent(): void {
		$queue = $this->queue();
		$accepted = $queue->accept(portal: 'gemeente-x', route: 'aanvragen/verhuizing', answers: []);
		$queue->markFailed(submission: $this->storedRows('portalIntakeSubmission')[0], reason: 'The case app refused the create.');

		$status = $queue->status(reference: $accepted['reference'], portal: 'gemeente-x');

		$this->assertSame('failed', $status['state']);
		$this->assertSame('The case app refused the create.', $status['failureReason']);
		$this->assertSame('', $status['caseId']);

	}//end testAFailedCreateIsVisibleNotSilent()

	public function testAReferenceIsNeverReadableFromAnotherPortal(): void {
		$queue = $this->queue();
		$accepted = $queue->accept(portal: 'gemeente-x', route: 'aanvragen/verhuizing', answers: []);

		$this->assertNull($queue->status(reference: $accepted['reference'], portal: 'gemeente-y'));
		$this->assertNull($queue->status(reference: 'AANVRAAG-NOPE', portal: 'gemeente-x'));

	}//end testAReferenceIsNeverReadableFromAnotherPortal()

	public function testOnlyQueuedSubmissionsAreHandedToTheJob(): void {
		$queue = $this->queue();
		$queue->accept(portal: 'gemeente-x', route: 'aanvragen/verhuizing', answers: []);
		$second = $queue->accept(portal: 'gemeente-x', route: 'aanvragen/verhuizing', answers: []);
		$queue->markRegistered(submission: $this->storedRows('portalIntakeSubmission')[1], caseId: 'zaak-1');

		$waiting = $queue->queued();

		$this->assertCount(1, $waiting);
		$this->assertNotSame($second['reference'], $waiting[0]['reference']);

	}//end testOnlyQueuedSubmissionsAreHandedToTheJob()

	public function testASubmissionThatCouldNotBeRecordedIsNotAcknowledged(): void {
		$writer = $this->getMockBuilder(\OCA\Portaliq\Service\PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['createObject', 'updateObject'])
			->getMock();
		$writer->method('createObject')->willReturn(null);
		$queue = new PortalIntakeQueue($this->fakeReader(), $writer, $this->fakeRandom());

		$this->assertNull($queue->accept(portal: 'gemeente-x', route: 'aanvragen/verhuizing', answers: []));

	}//end testASubmissionThatCouldNotBeRecordedIsNotAcknowledged()

	/**
	 * intake-pay-on-submit REQ-IPS-003: a submission is the resident's own
	 * only when its subjectRef is theirs; an anonymous one is nobody's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-only-the-submitter-can-pay-once-req-ips-003
	 */
	public function testOnlyTheSubmitterOwnsASubmission(): void {
		$queue = $this->queue();
		$mine = $queue->accept(portal: 'gemeente-x', route: 'aanvragen/parkeren', answers: [], subjectRef: 'bsn-1');
		$anonymous = $queue->accept(portal: 'gemeente-x', route: 'aanvragen/parkeren', answers: []);

		$this->assertSame($mine['reference'], $queue->ownSubmission(reference: $mine['reference'], portal: 'gemeente-x', subjectRef: 'bsn-1')['reference']);
		$this->assertNull($queue->ownSubmission(reference: $mine['reference'], portal: 'gemeente-x', subjectRef: 'bsn-2'));
		$this->assertNull($queue->ownSubmission(reference: $mine['reference'], portal: 'gemeente-y', subjectRef: 'bsn-1'));
		$this->assertNull($queue->ownSubmission(reference: $anonymous['reference'], portal: 'gemeente-x', subjectRef: ''));

	}//end testOnlyTheSubmitterOwnsASubmission()

	/**
	 * REQ-IPS-005: the payment record's id is noted on the submission, in a
	 * row the real register schema accepts, and read back by reference only;
	 * the public status answer does not carry it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-result-is-read-from-the-payment-record-req-ips-005
	 */
	public function testThePaymentIntentIsNotedOnTheSubmission(): void {
		$queue = $this->queue();
		$accepted = $queue->accept(portal: 'gemeente-x', route: 'aanvragen/parkeren', answers: ['kenteken' => 'AB-12-CD'], subjectRef: 'bsn-1');
		$this->assertSame('', $queue->paymentIntentOf(reference: $accepted['reference'], portal: 'gemeente-x'));

		$this->assertTrue($queue->recordPaymentIntent(submission: $this->storedRows('portalIntakeSubmission')[0], paymentIntentId: 'intent-1'));

		$row = $this->storedRows('portalIntakeSubmission')[0];
		$this->assertSame('intent-1', $row['paymentIntentId']);
		$this->assertSame('intent-1', $queue->paymentIntentOf(reference: $accepted['reference'], portal: 'gemeente-x'));
		$this->assertSame('', $queue->paymentIntentOf(reference: $accepted['reference'], portal: 'gemeente-y'));
		$this->assertArrayNotHasKey('paymentIntentId', $queue->status(reference: $accepted['reference'], portal: 'gemeente-x'));

		unset($row['uuid'], $row['id'], $row['_schema'], $row['@self']);
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../../lib/Settings/portaliq_register.json'), true);
		$schema = $register['components']['schemas']['portalIntakeSubmission'];
		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $schema['properties']]), false);
		$result = (new Validator())->validate(json_decode((string)json_encode($row), false), $jsonSchema);
		$this->assertTrue($result->isValid(), 'the submission fits the register schema');

	}//end testThePaymentIntentIsNotedOnTheSubmission()

	/**
	 * The queue over the fake store.
	 *
	 * @return PortalIntakeQueue
	 */
	private function queue(): PortalIntakeQueue {
		return new PortalIntakeQueue($this->fakeReader(), $this->fakeWriter(), $this->fakeRandom());
	}//end queue()

}//end class
