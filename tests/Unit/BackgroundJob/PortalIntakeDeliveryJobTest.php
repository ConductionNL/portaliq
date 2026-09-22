<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\PortalIntakeDeliveryJob;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
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
	 *
	 * @return PortalIntakeDeliveryJob
	 */
	private function job(PortalIntakeQueue $queue, PortalObjectWriter $writer, ?array $binding): PortalIntakeDeliveryJob {
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
			$this->createMock(LoggerInterface::class)
		);
	}//end job()

}//end class
