<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Service\Intake\IntakeQueueDrain;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * submit-creates-the-case-directly 4.1: the drain sends each waiting entry
 * through the same submit, keeps the old reference on the case and as the
 * Idempotency-Key, and reports what arrived, what was refused and what waits.
 *
 * @covers \OCA\Portaliq\Service\Intake\IntakeQueueDrain
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
 * @uses \OCA\Portaliq\Service\Intake\DestinationIntake
 * @uses \OCA\Portaliq\Service\Intake\DestinationMapping
 * @uses \OCA\Portaliq\Service\Intake\DestinationSubmit
 */
class IntakeQueueDrainTest extends TestCase {

	public function testAWaitingEntryArrivesWithItsOldReferenceAndIsMarkedRegistered(): void {
		$fake  = new FormSubmitFake(outcome: ['reference' => '2026-0042', 'id' => 'u-1', 'confirmation' => ['deadline' => '2026-11-24']]);
		$queue = $this->queue(pending: [[$this->entry()], []]);
		$queue->expects($this->once())->method('markRegistered')->with(
			$this->anything(),
			'u-1',
			'2026-0042',
			'2026-11-24T00:00:00+00:00'
		);
		$queue->expects($this->never())->method('markFailed');

		$result = $this->drain(queue: $queue, fake: $fake)->drain();

		$this->assertSame(['delivered' => 1, 'refused' => 0, 'waiting' => 0, 'reasons' => []], $result);
		$call = $fake->calls[0];
		$this->assertSame('AANVRAAG-ABC12345', $call['idempotencyKey']);
		$this->assertSame('AANVRAAG-ABC12345', $call['mapping']['fixed']['externalReference']);
		$this->assertSame('sub-123', $call['mapping']['fixed']['portalSubject']);
		$this->assertSame(['title' => 'Bezwaar'], $call['payload']);
	}//end testAWaitingEntryArrivesWithItsOldReferenceAndIsMarkedRegistered()

	public function testARefusedEntryIsMarkedFailedWithTheReasonAndStillWaits(): void {
		$fake  = new FormSubmitFake(outcome: FormSubmitFake::refusal(status: 422, findings: [['property' => 'title', 'code' => 'x', 'message' => 'Te lang.']]));
		$queue = $this->queue(pending: [[$this->entry()], [$this->entry()]]);
		$queue->expects($this->once())->method('markFailed')->with($this->anything(), $this->stringContains('Te lang.'));
		$queue->expects($this->never())->method('markRegistered');

		$result = $this->drain(queue: $queue, fake: $fake)->drain();

		$this->assertSame(0, $result['delivered']);
		$this->assertSame(1, $result['refused']);
		$this->assertSame(1, $result['waiting']);
		$this->assertArrayHasKey('AANVRAAG-ABC12345', $result['reasons']);
	}//end testARefusedEntryIsMarkedFailedWithTheReasonAndStillWaits()

	public function testAnEntryWhoseFormIsGoneIsRefusedWithoutASubmit(): void {
		$fake  = new FormSubmitFake(outcome: ['reference' => 'r']);
		$queue = $this->queue(pending: [[$this->entry()], [$this->entry()]]);
		$queue->expects($this->once())->method('markFailed')->with($this->anything(), 'The form this request came from is no longer published.');

		$this->drain(queue: $queue, fake: $fake, binding: null)->drain();

		$this->assertSame([], $fake->calls);
	}//end testAnEntryWhoseFormIsGoneIsRefusedWithoutASubmit()

	public function testADryRunOnlyCounts(): void {
		$fake  = new FormSubmitFake(outcome: ['reference' => 'r']);
		$queue = $this->queue(pending: [[$this->entry(), $this->entry()]]);
		$queue->expects($this->never())->method('markRegistered');

		$this->assertSame(2, $this->drain(queue: $queue, fake: $fake)->waiting());
		$this->assertSame([], $fake->calls);
	}//end testADryRunOnlyCounts()

	/**
	 * A queue double answering the given pending lists in turn.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $pending The answers to pending().
	 *
	 * @return PortalIntakeQueue&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function queue(array $pending): PortalIntakeQueue {
		$queue = $this->createMock(PortalIntakeQueue::class);
		$queue->method('pending')->willReturnOnConsecutiveCalls(...$pending);

		return $queue;
	}//end queue()

	/**
	 * The drain over the real intake, mapping and submit.
	 *
	 * @param PortalIntakeQueue         $queue   The queue.
	 * @param FormSubmitFake            $fake    OpenRegister's submit service.
	 * @param array<string, mixed>|null $binding The binding the entry came from.
	 *
	 * @return IntakeQueueDrain
	 */
	private function drain(PortalIntakeQueue $queue, FormSubmitFake $fake, ?array $binding = ['portal' => 'gemeente-x', 'route' => 'bezwaar', 'destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => 'portalSubject']]): IntakeQueueDrain {
		$bindings = $this->createMock(PortalFormBindingResolver::class);
		$bindings->method('bindingFor')->willReturn($binding);
		$bindings->method('render')->willReturn(['fields' => [['name' => 'title', 'type' => 'text']]]);

		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn(json_decode((string)file_get_contents(__DIR__ . '/../../../Fixtures/dossiq-case-schema-d179.json'), true)['schema']);

		$intake = new DestinationIntake(
			submitter: new DestinationSubmit($fake->container(), $this->createMock(LoggerInterface::class)),
			mapping: new DestinationMapping(),
			schemas: $schemas
		);

		return new IntakeQueueDrain(queue: $queue, bindings: $bindings, intake: $intake);
	}//end drain()

	/**
	 * One queued entry, as PortalIntakeQueue::accept() stored it.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(): array {
		return [
			'id' => 'q-1',
			'reference' => 'AANVRAAG-ABC12345',
			'portal' => 'gemeente-x',
			'route' => 'bezwaar',
			'subjectRef' => 'sub-123',
			'answers' => ['title' => 'Bezwaar'],
			'state' => 'queued',
			'computed' => ['fee'],
		];
	}//end entry()
}//end class
