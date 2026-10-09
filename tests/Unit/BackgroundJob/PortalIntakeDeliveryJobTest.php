<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\PortalIntakeDeliveryJob;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Service\Intake\PortalWooRequestDelivery;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * portal-intake-form-as-an-object REQ-PIFO-005: the case is created after the
 * citizen already has their reference, and a create that does not land marks
 * the submission failed with its reason rather than leaving it queued forever.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalIntakeDeliveryJobTest extends TestCase {

	public function testAQueuedSubmissionBecomesACase(): void {
		$queue = $this->queue();
		$queue->expects($this->once())->method('markRegistered')->with($this->anything(), $this->equalTo('zaak-1'));
		$queue->expects($this->never())->method('markFailed');

		$writer = $this->writer();
		$writer->method('createAnonymousObject')->willReturn(['id' => 'zaak-1']);

		$this->job(queue: $queue, writer: $writer, binding: $this->binding())->deliver(submission: $this->submission());

	}//end testAQueuedSubmissionBecomesACase()

	public function testTheAnswersAreWrittenWhereTheBindingSays(): void {
		$writer = $this->writer();
		$writer->expects($this->once())
			->method('createAnonymousObject')
			->with($this->equalTo('dossiq'), $this->equalTo('zaak'), $this->equalTo(['postcode' => '1234 AB']))
			->willReturn(['id' => 'zaak-1']);

		$this->job(queue: $this->queue(), writer: $writer, binding: $this->binding())->deliver(submission: $this->submission());

	}//end testTheAnswersAreWrittenWhereTheBindingSays()

	/**
	 * form-flow-repeating-groups-calculations-and-decisions T07: a field the
	 * portal worked out or a decision filled travels marked computed, and a
	 * submission without any is delivered exactly as before.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t07
	 */
	public function testComputedFieldsAreMarkedInTheDeliveredCase(): void {
		$writer = $this->writer();
		$writer->expects($this->once())
			->method('createAnonymousObject')
			->with(
				$this->equalTo('dossiq'),
				$this->equalTo('zaak'),
				$this->equalTo(['postcode' => '1234 AB', 'einddatum' => '2027-11-01', 'fieldMeta' => ['einddatum' => ['computed' => true]]])
			)
			->willReturn(['id' => 'zaak-1']);
		$submission = $this->submission();
		$submission['answers']['einddatum'] = '2027-11-01';
		$submission['computed'] = ['einddatum'];

		$this->job(queue: $this->queue(), writer: $writer, binding: $this->binding())->deliver(submission: $submission);

	}//end testComputedFieldsAreMarkedInTheDeliveredCase()

	public function testAFailedCreateMarksTheSubmissionFailedWithAReason(): void {
		$queue = $this->queue();
		$queue->expects($this->once())->method('markFailed')->with($this->anything(), $this->equalTo('The case could not be created.'));
		$queue->expects($this->never())->method('markRegistered');

		$writer = $this->writer();
		$writer->method('createAnonymousObject')->willReturn(null);

		$this->job(queue: $queue, writer: $writer, binding: $this->binding())->deliver(submission: $this->submission());

	}//end testAFailedCreateMarksTheSubmissionFailedWithAReason()

	public function testAThrownCreateIsAFailureNotACrash(): void {
		$queue = $this->queue();
		$queue->expects($this->once())->method('markFailed');

		$writer = $this->writer();
		$writer->method('createAnonymousObject')->willThrowException(new RuntimeException('OpenRegister is down'));

		$this->job(queue: $queue, writer: $writer, binding: $this->binding())->deliver(submission: $this->submission());

	}//end testAThrownCreateIsAFailureNotACrash()

	public function testASubmissionWhoseFormIsGoneFailsWithThatReason(): void {
		$queue = $this->queue();
		$queue->expects($this->once())->method('markFailed')->with($this->anything(), $this->equalTo('The form this request came from is no longer published.'));

		$writer = $this->writer();
		$writer->expects($this->never())->method('createAnonymousObject');

		$this->job(queue: $queue, writer: $writer, binding: null)->deliver(submission: $this->submission());

	}//end testASubmissionWhoseFormIsGoneFailsWithThatReason()

	public function testABindingWithNoCaseSchemaFailsRatherThanGuessing(): void {
		$queue = $this->queue();
		$queue->expects($this->once())->method('markFailed')->with($this->anything(), $this->equalTo('The form does not say which register the case belongs in.'));

		$writer = $this->writer();
		$writer->expects($this->never())->method('createAnonymousObject');

		$binding = $this->binding();
		unset($binding['caseSchema']);

		$this->job(queue: $queue, writer: $writer, binding: $binding)->deliver(submission: $this->submission());

	}//end testABindingWithNoCaseSchemaFailsRatherThanGuessing()

	/**
	 * A Woo-request form goes to opencatalogi's intake, not into a case
	 * register, and is registered with the reference and due date it armed.
	 *
	 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	public function testAWooRequestFormIsDeliveredToOpencatalogiAndArmsItsTerm(): void {
		$queue = $this->queue();
		$queue->expects($this->once())->method('markRegistered')->with(
			$this->anything(),
			$this->equalTo('req-1'),
			$this->equalTo('WOO-2026-1A2B3C'),
			$this->equalTo('2026-11-02T09:00:00+00:00')
		);
		$queue->expects($this->never())->method('markFailed');

		$writer = $this->writer();
		$writer->expects($this->never())->method('createAnonymousObject');

		$woo = $this->woo();
		$woo->expects($this->once())
			->method('deliver')
			->with($this->equalTo(['requestedInformation' => 'Alle stukken.']), $this->equalTo('2026-10-05T09:00:00+00:00'))
			->willReturn($this->wooOutcome(outcome: 'armed', dueAt: '2026-11-02T09:00:00+00:00'));

		$this->job(queue: $queue, writer: $writer, binding: ['deliverTo' => 'wooRequest'], woo: $woo)->deliver(submission: $this->wooSubmission());

	}//end testAWooRequestFormIsDeliveredToOpencatalogiAndArmsItsTerm()

	/**
	 * A Woo request whose term did not start is never registered, so the
	 * reference page never quotes a deadline.
	 *
	 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	public function testAWooRequestWhoseTermDidNotStartIsFailedNotRegistered(): void {
		$queue = $this->queue();
		$queue->expects($this->never())->method('markRegistered');
		$queue->expects($this->once())->method('markFailed')->with(
			$this->anything(),
			$this->equalTo('Woo request WOO-2026-1A2B3C was stored, but its statutory term did not start: no timer engine')
		);

		$woo = $this->woo();
		$woo->method('deliver')->willReturn($this->wooOutcome(outcome: 'not-armed', message: 'no timer engine'));

		$this->job(queue: $queue, writer: $this->writer(), binding: ['deliverTo' => 'wooRequest'], woo: $woo)->deliver(submission: $this->wooSubmission());

	}//end testAWooRequestWhoseTermDidNotStartIsFailedNotRegistered()

	/**
	 * Without opencatalogi a Woo request fails visibly, with the reason.
	 *
	 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	public function testAWooRequestWithoutOpencatalogiFailsWithTheReason(): void {
		$queue = $this->queue();
		$queue->expects($this->never())->method('markRegistered');
		$queue->expects($this->once())->method('markFailed')->with(
			$this->anything(),
			$this->equalTo('The Woo request could not be received: opencatalogi is not installed.')
		);

		$woo = $this->woo();
		$woo->method('deliver')->willReturn($this->wooOutcome(outcome: 'unavailable', message: 'opencatalogi is not installed.'));

		$this->job(queue: $queue, writer: $this->writer(), binding: ['deliverTo' => 'wooRequest'], woo: $woo)->deliver(submission: $this->wooSubmission());

	}//end testAWooRequestWithoutOpencatalogiFailsWithTheReason()

	/**
	 * A queued Woo request.
	 *
	 * @return array<string, mixed>
	 */
	private function wooSubmission(): array {
		return [
			'uuid' => 'submission-2',
			'portal' => 'gemeente-x',
			'route' => 'woo-verzoek',
			'answers' => ['requestedInformation' => 'Alle stukken.'],
			'state' => 'queued',
			'submittedAt' => '2026-10-05T09:00:00+00:00',
		];
	}//end wooSubmission()

	/**
	 * A Woo delivery double.
	 *
	 * @return PortalWooRequestDelivery&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function woo(): PortalWooRequestDelivery {
		return $this->getMockBuilder(PortalWooRequestDelivery::class)
			->disableOriginalConstructor()
			->onlyMethods(['deliver'])
			->getMock();
	}//end woo()

	/**
	 * One delivery outcome.
	 *
	 * @param string $outcome The outcome.
	 * @param string $dueAt The due date.
	 * @param string $message The reason.
	 *
	 * @return array{outcome: string, requestId: string, reference: string, dueAt: string, message: string}
	 */
	private function wooOutcome(string $outcome, string $dueAt = '', string $message = ''): array {
		return ['outcome' => $outcome, 'requestId' => 'req-1', 'reference' => 'WOO-2026-1A2B3C', 'dueAt' => $dueAt, 'message' => $message];
	}//end wooOutcome()

	/**
	 * One queued submission.
	 *
	 * @return array<string, mixed>
	 */
	private function submission(): array {
		return [
			'uuid' => 'submission-1',
			'portal' => 'gemeente-x',
			'route' => 'aanvragen/verhuizing',
			'answers' => ['postcode' => '1234 AB'],
			'state' => 'queued',
		];
	}//end submission()

	/**
	 * A binding naming where the case belongs.
	 *
	 * @return array<string, mixed>
	 */
	private function binding(): array {
		return ['caseRegister' => 'dossiq', 'caseSchema' => 'zaak'];
	}//end binding()

	/**
	 * A queue double.
	 *
	 * @return PortalIntakeQueue&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function queue(): PortalIntakeQueue {
		return $this->getMockBuilder(PortalIntakeQueue::class)
			->disableOriginalConstructor()
			->onlyMethods(['queued', 'markRegistered', 'markFailed'])
			->getMock();
	}//end queue()

	/**
	 * A writer double.
	 *
	 * @return PortalObjectWriter&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function writer(): PortalObjectWriter {
		return $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['createAnonymousObject'])
			->getMock();
	}//end writer()

	/**
	 * The job over its doubles.
	 *
	 * @param PortalIntakeQueue $queue The queue double.
	 * @param PortalObjectWriter $writer The writer double.
	 * @param array<string, mixed>|null $binding What the binding resolves to.
	 * @param PortalWooRequestDelivery|null $woo The Woo delivery double.
	 *
	 * @return PortalIntakeDeliveryJob
	 */
	private function job(PortalIntakeQueue $queue, PortalObjectWriter $writer, ?array $binding, ?PortalWooRequestDelivery $woo = null): PortalIntakeDeliveryJob {
		$bindings = $this->getMockBuilder(PortalFormBindingResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['bindingFor'])
			->getMock();
		$bindings->method('bindingFor')->willReturn($binding);

		return new PortalIntakeDeliveryJob(
			$this->createMock(ITimeFactory::class),
			$queue,
			$bindings,
			$writer,
			$this->createMock(LoggerInterface::class),
			$woo ?? $this->woo()
		);
	}//end job()

}//end class
