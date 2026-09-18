<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
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
	 * The queue over the fake store.
	 *
	 * @return PortalIntakeQueue
	 */
	private function queue(): PortalIntakeQueue {
		return new PortalIntakeQueue($this->fakeReader(), $this->fakeWriter(), $this->fakeRandom());
	}//end queue()

}//end class
