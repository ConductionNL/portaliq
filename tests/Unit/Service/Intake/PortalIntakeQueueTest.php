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

	/**
	 * submit-creates-the-case-directly 4.1: the drain takes queued and failed
	 * entries, never registered ones.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	public function testTheDrainTakesQueuedAndFailedEntries(): void {
		$queue = $this->queue();
		foreach ([1, 2, 3] as $n) {
			$queue->accept(portal: 'gemeente-x', route: 'aanvragen/verhuizing', answers: ['n' => $n]);
		}

		$queue->markRegistered(submission: $this->storedRows('portalIntakeSubmission')[0], caseId: 'zaak-1');
		$queue->markFailed(submission: $this->storedRows('portalIntakeSubmission')[1], reason: 'refused');

		$pending = $queue->pending();

		$this->assertEqualsCanonicalizing(['queued', 'failed'], array_column($pending, 'state'));
	}//end testTheDrainTakesQueuedAndFailedEntries()

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
	 * The exact row a submission writes: every extra it carries, the computed
	 * names re-indexed, and nothing for the extras it does not carry.
	 *
	 * @return void
	 */
	public function testTheRecordedRowCarriesEachExtraOnlyWhenGiven(): void {
		$queue = $this->queue();

		$accepted = $queue->accept(
			portal: 'gemeente-x',
			route: 'woo',
			answers: ['a' => 1],
			subjectRef: 'subject-1',
			origin: 'https://example.org',
			statements: [['key' => 'truth']],
			computed: [3 => 'fee', 7 => 'total'],
			decisions: ['step-1' => 'yes'],
			verifiedEmails: [['address' => 'a@example.org', 'at' => 'now']]
		);
		$queue->accept(portal: 'gemeente-x', route: 'kort', answers: []);

		$this->assertSame(['reference' => 'AANVRAAG-SECRET-1', 'state' => 'queued'], $accepted);
		[$full, $bare] = $this->storedRows('portalIntakeSubmission');
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $full['submittedAt']);
		unset($full['submittedAt'], $full['uuid'], $full['_schema'], $bare['submittedAt'], $bare['uuid'], $bare['_schema']);
		$this->assertSame(
			[
				'reference' => 'AANVRAAG-SECRET-1',
				'portal' => 'gemeente-x',
				'route' => 'woo',
				'subjectRef' => 'subject-1',
				'origin' => 'https://example.org',
				'answers' => ['a' => 1],
				'state' => 'queued',
				'statements' => [['key' => 'truth']],
				'computed' => ['fee', 'total'],
				'decisions' => ['step-1' => 'yes'],
				'verifiedEmails' => [['address' => 'a@example.org', 'at' => 'now']],
			],
			$full
		);
		$this->assertSame(
			['reference' => 'AANVRAAG-SECRET-2', 'portal' => 'gemeente-x', 'route' => 'kort', 'subjectRef' => '', 'origin' => '', 'answers' => [], 'state' => 'queued'],
			$bare
		);
		$this->assertNull($queue->accept(portal: '', route: 'woo', answers: []));
		$this->assertCount(2, $this->storedRows('portalIntakeSubmission'));

	}//end testTheRecordedRowCarriesEachExtraOnlyWhenGiven()

	/**
	 * The queue over the fake store.
	 *
	 * @return PortalIntakeQueue
	 */
	/**
	 * resident-identity-in-forms T04: Mijn zaken lists the resident's own
	 * requests on this portal that wait for a co-signer or were not
	 * co-signed, newest first, with the waiting line; nothing of another
	 * resident, another portal, or a request already on its way.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function testHeldForListsOnlyTheResidentsOwnHeldRequestsOnThisPortal(): void {
		$waiting = ['state' => 'awaiting', 'name' => 'Henk de Vries', 'deadline' => '2026-10-22T23:59:59+02:00', 'codeHash' => 'h', 'email' => 'henk@example.nl'];
		$this->seed(reference: 'AANVRAAG-OLD', subjectRef: 'bsn-sanne', portal: 'gemeente-x', state: 'not-cosigned', submittedAt: '2026-10-01T10:00:00+02:00', cosign: ['state' => 'expired'] + $waiting);
		$this->seed(reference: 'AANVRAAG-NEW', subjectRef: 'bsn-sanne', portal: 'gemeente-x', state: 'awaiting-cosign', submittedAt: '2026-10-08T10:00:00+02:00', cosign: $waiting);
		$this->seed(reference: 'AANVRAAG-Y', subjectRef: 'bsn-sanne', portal: 'gemeente-y', state: 'awaiting-cosign', submittedAt: '2026-10-08T11:00:00+02:00', cosign: $waiting);
		$this->seed(reference: 'AANVRAAG-HENK', subjectRef: 'bsn-henk', portal: 'gemeente-x', state: 'awaiting-cosign', submittedAt: '2026-10-08T12:00:00+02:00', cosign: $waiting);
		$this->seed(reference: 'AANVRAAG-SENT', subjectRef: 'bsn-sanne', portal: 'gemeente-x', state: 'queued', submittedAt: '2026-10-09T10:00:00+02:00', cosign: ['state' => 'signed'] + $waiting);

		$held = $this->queue()->heldFor(subjectRef: 'bsn-sanne', portal: 'gemeente-x');

		$this->assertSame(
			[
				[
					'reference' => 'AANVRAAG-NEW',
					'route' => 'woo',
					'submittedAt' => '2026-10-08T10:00:00+02:00',
					'state' => 'awaiting-cosign',
					'cosign' => ['state' => 'awaiting', 'name' => 'Henk de Vries', 'deadline' => '2026-10-22T23:59:59+02:00'],
				],
				[
					'reference' => 'AANVRAAG-OLD',
					'route' => 'woo',
					'submittedAt' => '2026-10-01T10:00:00+02:00',
					'state' => 'not-cosigned',
					'cosign' => ['state' => 'expired', 'name' => 'Henk de Vries', 'deadline' => '2026-10-22T23:59:59+02:00'],
				],
			],
			$held
		);
	}//end testHeldForListsOnlyTheResidentsOwnHeldRequestsOnThisPortal()

	/**
	 * An anonymous submitter or an unknown portal has no held requests to list.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function testHeldForWithoutASubjectOrPortalListsNothing(): void {
		$this->seed(reference: 'AANVRAAG-ANON', subjectRef: '', portal: 'gemeente-x', state: 'awaiting-cosign', submittedAt: '2026-10-08T10:00:00+02:00', cosign: ['state' => 'awaiting']);

		$this->assertSame([], $this->queue()->heldFor(subjectRef: '', portal: 'gemeente-x'));
		$this->assertSame([], $this->queue()->heldFor(subjectRef: 'bsn-sanne', portal: ''));
	}//end testHeldForWithoutASubjectOrPortalListsNothing()

	/**
	 * Put a submission row in the fake store.
	 *
	 * @param string               $reference   The reference.
	 * @param string               $subjectRef  The submitter.
	 * @param string               $portal      The portal.
	 * @param string               $state       The submission state.
	 * @param string               $submittedAt When it was submitted.
	 * @param array<string, mixed> $cosign      The co-sign block.
	 *
	 * @return void
	 */
	private function seed(string $reference, string $subjectRef, string $portal, string $state, string $submittedAt, array $cosign): void {
		$this->rows[$reference] = [
			'_schema' => 'portalIntakeSubmission',
			'reference' => $reference,
			'portal' => $portal,
			'route' => 'woo',
			'subjectRef' => $subjectRef,
			'answers' => [],
			'state' => $state,
			'submittedAt' => $submittedAt,
			'cosign' => $cosign,
		];
	}//end seed()

	private function queue(): PortalIntakeQueue {
		return new PortalIntakeQueue($this->fakeReader(), $this->fakeWriter(), $this->fakeRandom());
	}//end queue()

}//end class
