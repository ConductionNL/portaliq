<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Service\Intake\LandingSubmissionDrain;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * submit-creates-the-case-directly 4.1 (landing half): a stored landing-page
 * submission is submitted into its form's destination with its own id as
 * externalReference and Idempotency-Key, and deleted only after that. A
 * submission whose form names no destination, or that the destination
 * refuses, is reported and kept.
 *
 * @covers \OCA\Portaliq\Service\Intake\LandingSubmissionDrain
 * @uses \OCA\Portaliq\Service\Intake\DestinationIntake
 * @uses \OCA\Portaliq\Service\Intake\DestinationMapping
 * @uses \OCA\Portaliq\Service\Intake\DestinationSubmit
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
 */
class LandingSubmissionDrainTest extends TestCase {

	/**
	 * Stored submissions by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	private FormSubmitFake $fake;

	public function testASubmissionGoesIntoItsFormsDestinationAndOnlyThenIsDeleted(): void {
		$this->rows = [
			's-1' => ['id' => 's-1', 'formId' => 'form-a', 'portal' => 'open-tilburg', 'pageRoute' => '/webinar', 'nonce' => 'n', 'email' => 'ada@example.nl', 'name' => 'Ada'],
			's-2' => ['uuid' => 's-2', 'formId' => 'form-old', 'values' => ['email' => 'b@example.nl']],
			's-3' => ['id' => 's-3', 'formId' => 'form-gone', 'email' => 'c@example.nl'],
		];

		$drain  = $this->drain(outcome: ['reference' => 'LEAD-1', 'id' => 'l-1']);
		$result = $drain->drain();
		$call   = $this->fake->calls[0];

		$this->assertSame(1, $result['delivered']);
		$this->assertSame(2, $result['refused']);
		$this->assertSame(['s-2' => 'its form names no destination', 's-3' => 'its form names no destination'], $result['reasons']);
		$this->assertSame(2, $result['waiting']);
		$this->assertSame(['s-2', 's-3'], array_keys($this->rows));
		$this->assertSame(['register' => 'pipelinq', 'schema' => 'lead'], $call['destination']);
		$this->assertSame(['email' => 'ada@example.nl', 'name' => 'Ada'], $call['payload']);
		$this->assertSame('s-1', $call['mapping']['fixed']['externalReference']);
		$this->assertSame('form-a', $call['mapping']['fixed']['formId']);
		$this->assertSame('s-1', $call['idempotencyKey']);
		$this->assertSame(2, $drain->waiting());
	}//end testASubmissionGoesIntoItsFormsDestinationAndOnlyThenIsDeleted()

	public function testARefusedSubmissionIsReportedAndKept(): void {
		$this->rows = ['s-1' => ['id' => 's-1', 'formId' => 'form-a', 'values' => ['email' => 'not an address']]];

		$result = $this->drain(outcome: FormSubmitFake::refusal(status: 422, findings: [['property' => 'email', 'message' => 'Fill in an e-mail address.']]))->drain();

		$this->assertSame(['s-1' => 'the destination refused it (422): Fill in an e-mail address.'], $result['reasons']);
		$this->assertArrayHasKey('s-1', $this->rows);
	}//end testARefusedSubmissionIsReportedAndKept()

	public function testADeliveredSubmissionThatCannotBeDeletedIsReported(): void {
		$this->rows = ['s-1' => ['id' => 's-1', 'formId' => 'form-a', 'values' => ['email' => 'a@example.nl']]];

		$result = $this->drain(outcome: ['id' => 'l-1'], deletes: false)->drain();

		$this->assertSame(['s-1' => 'it was delivered but could not be deleted'], $result['reasons']);
	}//end testADeliveredSubmissionThatCannotBeDeletedIsReported()

	/**
	 * The drain over fakes.
	 *
	 * @param array<string, mixed>|\Throwable $outcome What the submit answers.
	 */
	private function drain(array|\Throwable $outcome, bool $deletes = true): LandingSubmissionDrain {
		$forms  = [
			['id' => 'form-a', 'destination' => ['register' => 'pipelinq', 'schema' => 'lead']],
			['id' => 'form-old', 'destination' => []],
			'not-a-form',
		];
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			fn (string $register, string $schema): array => ($schema === 'form' ? $forms : array_merge(array_values($this->rows), ['not-a-row']))
		);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('deleteObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id) use ($deletes): bool {
				$this->assertSame('formId', $scopeField);
				if ($deletes === true) {
					unset($this->rows[$id]);
				}

				return $deletes;
			}
		);
		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn(['properties' => ['email' => [], 'name' => [], 'formId' => [], 'externalReference' => []]]);

		$this->fake = new FormSubmitFake(outcome: $outcome);

		return new LandingSubmissionDrain(
			reader: $reader,
			writer: $writer,
			intake: new DestinationIntake(
				submitter: new DestinationSubmit($this->fake->container(), $this->createMock(LoggerInterface::class)),
				mapping: new DestinationMapping(),
				schemas: $schemas
			)
		);
	}//end drain()
}//end class
