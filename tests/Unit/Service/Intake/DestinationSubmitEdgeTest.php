<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The fallbacks of DestinationSubmit that the main test leaves out: a submit
 * service that answers nothing usable, an exception that looks like a refusal
 * but carries no error status, a service that resolves to a non-object, and a
 * check whose schema read or validator fails or answers no list. Each answers
 * "try again later" (503) or "could not be checked" (null), never success.
 *
 * @covers \OCA\Portaliq\Service\Intake\DestinationSubmit
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md
 */
class DestinationSubmitEdgeTest extends TestCase {

	private const SUBMIT = 'OCA\\OpenRegister\\Service\\Form\\FormSubmitService';
	private const VALIDATOR = 'OCA\\OpenRegister\\Service\\Form\\FormDestinationValidator';
	private const MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * A container over exactly these services.
	 *
	 * @param array<string, mixed> $services The services by name; a missing one throws.
	 *
	 * @return ContainerInterface
	 */
	private static function container(array $services): ContainerInterface {
		return new class($services) implements ContainerInterface {
			/**
			 * @param array<string, mixed> $services The services.
			 */
			public function __construct(private array $services) {
			}

			public function get(string $id): mixed {
				if (array_key_exists($id, $this->services) === false) {
					throw new RuntimeException('not found: ' . $id);
				}

				return $this->services[$id];
			}

			public function has(string $id): bool {
				return array_key_exists($id, $this->services);
			}
		};
	}//end container()

	/**
	 * A schema mapper answering a fixed result, or throwing it.
	 *
	 * @param mixed $result What findBySlug answers; a Throwable is thrown.
	 *
	 * @return object
	 */
	private static function mapper(mixed $result): object {
		return new class($result) {
			public array $asked = [];

			public function __construct(private mixed $result) {
			}

			public function findBySlug(string $slug, int $limit, int $offset, bool $_rbac, bool $_multitenancy): mixed {
				$this->asked[] = $slug;
				if ($this->result instanceof \Throwable) {
					throw $this->result;
				}

				return $this->result;
			}
		};
	}//end mapper()

	/**
	 * A validator answering a fixed result, or throwing it.
	 *
	 * @param mixed $result What validate answers; a Throwable is thrown.
	 *
	 * @return object
	 */
	private static function validator(mixed $result): object {
		return new class($result) {
			public function __construct(private mixed $result) {
			}

			public ?object $destination = null;

			public function validate(array $mapping, object $destination): mixed {
				$this->destination = $destination;
				if ($this->result instanceof \Throwable) {
					throw $this->result;
				}

				return $this->result;
			}
		};
	}//end validator()

	public function testASubmitServiceThatAnswersNoListIsTryAgainLater(): void {
		$service = new class {
			public int $calls = 0;

			public function submit(object $submission, ?string $idempotencyKey=null): mixed {
				$this->calls++;
				return 'created';
			}
		};

		$result = (new DestinationSubmit(self::container([self::SUBMIT => $service]), $this->createMock(LoggerInterface::class)))
			->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: ['x' => 1]);

		$this->assertSame(1, $service->calls);
		$this->assertSame(
			['ok' => false, 'status' => 503, 'answer' => [], 'message' => 'The destination gave no answer.', 'findings' => []],
			$result
		);
	}//end testASubmitServiceThatAnswersNoListIsTryAgainLater()

	public function testAServiceThatResolvesToNoObjectIsUnreachable(): void {
		$result = (new DestinationSubmit(self::container([self::SUBMIT => 'not a service']), $this->createMock(LoggerInterface::class)))
			->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: []);

		$this->assertSame(['ok' => false, 'status' => 503, 'message' => 'The destination cannot be reached.'], array_intersect_key($result, array_flip(['ok', 'status', 'message'])));
	}//end testAServiceThatResolvesToNoObjectIsUnreachable()

	public function testARefusalShapedExceptionWithoutAnErrorStatusIsUnreachableAndLogged(): void {
		$redirect = new class('moved') extends RuntimeException {
			public function getStatus(): int {
				return 302;
			}

			public function getFindings(): array {
				return [['property' => 'x']];
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Portaliq: a form submit could not reach its destination',
			['schema' => 'case', 'reason' => 'moved']
		);

		$result = (new DestinationSubmit((new FormSubmitFake(outcome: $redirect))->container(), $logger))
			->submit(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: [], payload: []);

		$this->assertSame(503, $result['status']);
		$this->assertSame([], $result['findings']);
	}//end testARefusalShapedExceptionWithoutAnErrorStatusIsUnreachableAndLogged()

	public function testARefusalDropsFindingsThatAreNoList(): void {
		$refusal = new class('forbidden') extends RuntimeException {
			public function getStatus(): int {
				return 403;
			}

			public function getFindings(): array {
				return ['loose string', ['property' => 'owner', 'code' => 'forbidden']];
			}
		};

		$result = (new DestinationSubmit((new FormSubmitFake(outcome: $refusal))->container(), $this->createMock(LoggerInterface::class)))
			->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: []);

		$this->assertSame(
			['ok' => false, 'status' => 403, 'answer' => [], 'message' => 'forbidden', 'findings' => [['property' => 'owner', 'code' => 'forbidden']]],
			$result
		);
	}//end testARefusalDropsFindingsThatAreNoList()

	public function testAFailingValidatorIsLoggedAndCouldNotBeChecked(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Portaliq: a form binding could not be checked', ['reason' => 'validator crashed']);
		$container = self::container([self::VALIDATOR => self::validator(new RuntimeException('validator crashed')), self::MAPPER => self::mapper([new \stdClass()])]);

		$this->assertNull((new DestinationSubmit($container, $logger))->findings(destination: ['register' => 'a', 'schema' => 'case'], mapping: [], audience: 'public'));
	}//end testAFailingValidatorIsLoggedAndCouldNotBeChecked()

	public function testAValidatorThatAnswersNoListCouldNotCheck(): void {
		$container = self::container([self::VALIDATOR => self::validator('fine'), self::MAPPER => self::mapper([new \stdClass()])]);

		$this->assertNull((new DestinationSubmit($container, $this->createMock(LoggerInterface::class)))->findings(destination: ['register' => 'a', 'schema' => 'case'], mapping: [], audience: 'authenticated'));
	}//end testAValidatorThatAnswersNoListCouldNotCheck()

	public function testAFailingSchemaReadIsLoggedWithItsSlug(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Portaliq: a destination schema could not be read', ['slug' => 'case', 'reason' => 'db down']);
		$container = self::container([self::VALIDATOR => self::validator([]), self::MAPPER => self::mapper(new RuntimeException('db down'))]);

		$this->assertNull((new DestinationSubmit($container, $logger))->findings(destination: ['register' => 'a', 'schema' => 'case'], mapping: [], audience: 'public'));
	}//end testAFailingSchemaReadIsLoggedWithItsSlug()

	public function testASchemaReadWithoutAnEntityCouldNotCheck(): void {
		foreach ([null, [], ['not an entity']] as $answer) {
			$container = self::container([self::VALIDATOR => self::validator([]), self::MAPPER => self::mapper($answer)]);
			$this->assertNull(
				(new DestinationSubmit($container, $this->createMock(LoggerInterface::class)))->findings(destination: ['register' => 'a', 'schema' => 'case'], mapping: [], audience: 'public'),
				var_export($answer, true)
			);
		}
	}//end testASchemaReadWithoutAnEntityCouldNotCheck()

	public function testAnEmptySchemaSlugIsNeverLookedUp(): void {
		$mapper = self::mapper([new \stdClass()]);
		$container = self::container([self::VALIDATOR => self::validator([]), self::MAPPER => $mapper]);

		$this->assertNull((new DestinationSubmit($container, $this->createMock(LoggerInterface::class)))->findings(destination: ['register' => 'a'], mapping: [], audience: 'public'));
		$this->assertSame([], $mapper->asked);
	}//end testAnEmptySchemaSlugIsNeverLookedUp()

	public function testAValidMappingHasNoFindings(): void {
		$container = self::container([self::VALIDATOR => self::validator([]), self::MAPPER => self::mapper([new \stdClass()])]);

		$this->assertSame([], (new DestinationSubmit($container, $this->createMock(LoggerInterface::class)))->findings(destination: ['register' => 'a', 'schema' => 'case'], mapping: [], audience: 'public'));
	}//end testAValidMappingHasNoFindings()

	/**
	 * OpenRegister's validator takes one FormDestination with the schema and
	 * the audience; the older named arguments threw "Unknown named parameter".
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md
	 */
	public function testTheValidatorIsHandedTheSchemaAndTheAudienceAsOneDestination(): void {
		$schema    = new \stdClass();
		$validator = self::validator([]);
		$container = self::container([self::VALIDATOR => $validator, self::MAPPER => self::mapper([$schema])]);

		$this->assertSame([], (new DestinationSubmit($container, $this->createMock(LoggerInterface::class)))->findings(destination: ['register' => 'a', 'schema' => 'case'], mapping: [], audience: 'public'));
		$this->assertInstanceOf(\OCA\OpenRegister\Service\Form\FormDestination::class, $validator->destination);
		$this->assertSame($schema, $validator->destination->schema);
		$this->assertSame('public', $validator->destination->audience);
	}//end testTheValidatorIsHandedTheSchemaAndTheAudienceAsOneDestination()
}//end class
