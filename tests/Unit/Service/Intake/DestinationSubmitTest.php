<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * submit-creates-the-case-directly 2.1/2.3: the submit reaches OpenRegister's
 * FormSubmitService in process, anonymous and under RBAC, and every refusal
 * keeps its status: 422 with findings, 503 when nothing answers.
 *
 * @covers \OCA\Portaliq\Service\Intake\DestinationSubmit
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md
 */
class DestinationSubmitTest extends TestCase {

	public function testTheSubmitGoesToOpenRegisterAsAnAnonymousWriteAndAnswersItsReference(): void {
		$fake   = new FormSubmitFake(outcome: ['reference' => '2026-0042', 'id' => 'u-1', 'receivedAt' => '2026-10-11T09:00:00+00:00', 'confirmation' => ['deadline' => '2026-11-22']]);
		$result = (new DestinationSubmit($fake->container(), $this->createMock(LoggerInterface::class)))->submit(
			destination: ['register' => 'dossiq', 'schema' => 'case'],
			mapping: ['fields' => [['field' => 'title', 'property' => 'title']], 'fixed' => []],
			payload: ['title' => 'Bezwaar'],
			idempotencyKey: 'key-1',
			scope: 'gemeente-x/bezwaar'
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('2026-0042', $result['answer']['reference']);
		$this->assertCount(1, $fake->calls);
		$this->assertNull($fake->calls[0]['subject']);
		$this->assertSame('key-1', $fake->calls[0]['idempotencyKey']);
		$this->assertSame('gemeente-x/bezwaar', $fake->calls[0]['scope']);
		$this->assertSame(['title' => 'Bezwaar'], $fake->calls[0]['payload']);
	}//end testTheSubmitGoesToOpenRegisterAsAnAnonymousWriteAndAnswersItsReference()

	public function testNoKeyIsSentAsNoKey(): void {
		$fake = new FormSubmitFake(outcome: ['reference' => 'r']);
		(new DestinationSubmit($fake->container(), $this->createMock(LoggerInterface::class)))->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: []);

		$this->assertNull($fake->calls[0]['idempotencyKey']);
	}//end testNoKeyIsSentAsNoKey()

	public function testARefusalKeepsItsStatusAndFindings(): void {
		$findings = [['property' => 'title', 'code' => 'required-unmapped', 'message' => 'A title is required.']];
		$fake     = new FormSubmitFake(outcome: FormSubmitFake::refusal(status: 422, findings: $findings));

		$result = (new DestinationSubmit($fake->container(), $this->createMock(LoggerInterface::class)))->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: []);

		$this->assertFalse($result['ok']);
		$this->assertSame(422, $result['status']);
		$this->assertSame($findings, $result['findings']);
		$this->assertSame([], $result['answer']);
	}//end testARefusalKeepsItsStatusAndFindings()

	public function testAnyOtherFailureIsTryAgainLaterAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$fake = new FormSubmitFake(outcome: new RuntimeException('database gone'));

		$result = (new DestinationSubmit($fake->container(), $logger))->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: []);

		$this->assertSame(503, $result['status']);
	}//end testAnyOtherFailureIsTryAgainLaterAndIsLogged()

	public function testWithoutOpenRegisterTheSubmitIsTryAgainLater(): void {
		$empty  = (new FormSubmitFake())->container();
		$none   = new class implements \Psr\Container\ContainerInterface {
			public function get(string $id): mixed {
				throw new RuntimeException('none');
			}

			public function has(string $id): bool {
				return false;
			}
		};
		$result = (new DestinationSubmit($none, $this->createMock(LoggerInterface::class)))->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: []);

		$this->assertFalse($result['ok']);
		$this->assertSame(503, $result['status']);
		$this->assertTrue($empty->has('OCA\\OpenRegister\\Service\\Form\\FormSubmitService'));
	}//end testWithoutOpenRegisterTheSubmitIsTryAgainLater()

	public function testTheCheckAsksTheValidatorWithTheSchemaEntityAndTheAudience(): void {
		$schema    = new \stdClass();
		$validator = new class {
			public array $asked = [];

			public function validate(array $mapping, object $schema, array $options = []): array {
				$this->asked = compact('mapping', 'schema', 'options');
				return [['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'No case type.'], 'not-a-finding'];
			}
		};
		$mapper = new class($schema) {
			public function __construct(private object $schema) {
			}

			public function findBySlug(string $slug, int $limit, int $offset, bool $_rbac, bool $_multitenancy): array {
				return $slug === 'case' ? [$this->schema] : [];
			}
		};
		$container = (new FormSubmitFake())->container([
			'OCA\\OpenRegister\\Service\\Form\\FormDestinationValidator' => $validator,
			'OCA\\OpenRegister\\Db\\SchemaMapper' => $mapper,
		]);

		$submit   = new DestinationSubmit($container, $this->createMock(LoggerInterface::class));
		$findings = $submit->findings(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: ['fields' => []], audience: 'public');

		$this->assertSame([['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'No case type.']], $findings);
		$this->assertTrue($validator->asked['schema'] === $schema);
		$this->assertSame(['audience' => 'public'], $validator->asked['options']);
		$this->assertNull($submit->findings(destination: ['register' => 'dossiq', 'schema' => 'unknown'], mapping: [], audience: 'public'));
	}//end testTheCheckAsksTheValidatorWithTheSchemaEntityAndTheAudience()

	public function testWithoutTheValidatorTheCheckSaysItCouldNotCheck(): void {
		$submit = new DestinationSubmit((new FormSubmitFake())->container(), $this->createMock(LoggerInterface::class));

		$this->assertNull($submit->findings(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: [], audience: 'public'));
	}//end testWithoutTheValidatorTheCheckSaysItCouldNotCheck()
}//end class
