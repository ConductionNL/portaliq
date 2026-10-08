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

	/**
	 * form-statements-intro-and-confirmation-mail T03: the accepted statements
	 * are stored on the submission with their text version and time.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
	 */
	public function testAcceptedStatementsAreStoredOnTheSubmission(): void {
		$queue = $this->queue();
		$statements = [['key' => 'truth', 'textVersion' => '2', 'acceptedAt' => '2026-10-08T10:00:00+00:00']];

		$queue->accept(portal: 'gemeente-x', route: 'woo', answers: [], statements: $statements);
		$queue->accept(portal: 'gemeente-x', route: 'woo', answers: []);

		$rows = $this->storedRows('portalIntakeSubmission');
		$this->assertSame($statements, $rows[0]['statements']);
		$this->assertArrayNotHasKey('statements', $rows[1]);
	}//end testAcceptedStatementsAreStoredOnTheSubmission()

	/**
	 * T05: a mail that failed is recorded on the submission; any other word is refused.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 */
	public function testTheConfirmationMailOutcomeIsRecorded(): void {
		$queue = $this->queue();
		$accepted = $queue->accept(portal: 'gemeente-x', route: 'woo', answers: []);

		$this->assertTrue($queue->markConfirmationMail($accepted['reference'], 'gemeente-x', 'failed'));
		$this->assertSame('failed', $this->storedRows('portalIntakeSubmission')[0]['confirmationMailState']);
		$this->assertFalse($queue->markConfirmationMail($accepted['reference'], 'gemeente-x', 'maybe'));
		$this->assertFalse($queue->markConfirmationMail($accepted['reference'], 'andere-gemeente', 'sent'));
		$this->assertFalse($queue->markConfirmationMail('AANVRAAG-NOPE', 'gemeente-x', 'sent'));
	}//end testTheConfirmationMailOutcomeIsRecorded()

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

	/**
	 * A Woo request registered with its term answers the minted reference and
	 * the due date; one that failed answers neither.
	 *
	 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	public function testARegisteredWooRequestNamesItsReferenceAndDueDate(): void {
		$queue = $this->queue();
		$accepted = $queue->accept(portal: 'gemeente-x', route: 'woo-verzoek', answers: []);
		$queue->markRegistered(
			submission: $this->storedRows('portalIntakeSubmission')[0],
			caseId: 'req-1',
			externalReference: 'WOO-2026-1A2B3C',
			dueAt: '2026-11-02T09:00:00+00:00'
		);

		$status = $queue->status(reference: $accepted['reference'], portal: 'gemeente-x');

		$this->assertSame('registered', $status['state']);
		$this->assertSame('WOO-2026-1A2B3C', $status['externalReference']);
		$this->assertSame('2026-11-02T09:00:00+00:00', $status['dueAt']);

	}//end testARegisteredWooRequestNamesItsReferenceAndDueDate()

	/**
	 * A case without a term never writes an empty date-time.
	 *
	 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	public function testACaseWithoutATermWritesNoDueDate(): void {
		$queue = $this->queue();
		$accepted = $queue->accept(portal: 'gemeente-x', route: 'aanvragen/verhuizing', answers: []);
		$queue->markRegistered(submission: $this->storedRows('portalIntakeSubmission')[0], caseId: 'zaak-1');

		$this->assertArrayNotHasKey('dueAt', $this->storedRows('portalIntakeSubmission')[0]);
		$this->assertSame('', $queue->status(reference: $accepted['reference'], portal: 'gemeente-x')['dueAt']);

	}//end testACaseWithoutATermWritesNoDueDate()

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
