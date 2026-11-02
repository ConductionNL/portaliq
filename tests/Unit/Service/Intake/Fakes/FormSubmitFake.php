<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake\Fakes;

use Throwable;

/**
 * Stands in for OpenRegister's FormSubmitService (openregister#4563), with
 * its submit() signature: it records each call and answers what the test
 * set, or throws it. Nothing is stored anywhere.
 */
class FormSubmitFake {

	/**
	 * Every call, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|Throwable $outcome What submit() answers or throws.
	 */
	public function __construct(public array|Throwable $outcome = []) {
	}//end __construct()

	/**
	 * OpenRegister's signature.
	 *
	 * @param array<string, mixed>      $destination    The destination.
	 * @param array<string, mixed>|null $mapping        The mapping.
	 * @param array<string, mixed>      $payload        The payload.
	 * @param mixed                     $subject        The subject.
	 * @param string|null               $idempotencyKey The key.
	 * @param string                    $scope          The scope.
	 * @param string|null               $draftId        The draft.
	 *
	 * @return array<string, mixed>
	 */
	public function submit(array $destination, ?array $mapping, array $payload, mixed $subject = null, ?string $idempotencyKey = null, string $scope = '', ?string $draftId = null, mixed $owner = null): array {
		$this->calls[] = compact('destination', 'mapping', 'payload', 'subject', 'idempotencyKey', 'scope', 'draftId', 'owner');
		if ($this->outcome instanceof Throwable) {
			throw $this->outcome;
		}

		return $this->outcome;
	}//end submit()

		/**
	 * FormSubmitService::saveDraft's signature (decisions 180 and 195).
	 *
	 * @return array<string, mixed>
	 */
	public function saveDraft(array $destination, ?array $mapping, array $payload, mixed $subject, ?string $draftId = null, string $scope = '', mixed $owner = null): array {
		$this->calls[] = ['saveDraft' => true] + compact('destination', 'mapping', 'payload', 'subject', 'draftId', 'scope', 'owner');
		if ($this->outcome instanceof Throwable) {
			throw $this->outcome;
		}

		return $this->outcome;
	}//end saveDraft()

	/**
	 * FormSubmitService::findDraft's signature: answers `$found`.
	 *
	 * @return array<string, mixed>|null
	 */
	public function findDraft(array $destination, mixed $subject, mixed $owner = null, array $match = []): ?array {
		$this->calls[] = ['findDraft' => true] + compact('destination', 'subject', 'owner', 'match');
		if ($this->found instanceof Throwable) {
			throw $this->found;
		}

		return $this->found;
	}//end findDraft()

	/**
	 * What findDraft answers.
	 *
	 * @var array<string, mixed>|Throwable|null
	 */
	public array|Throwable|null $found = null;

	/**
	 * A container that hands out this fake as the submit service.
	 *
	 * @param array<string, object> $more Other services by class name.
	 *
	 * @return \Psr\Container\ContainerInterface
	 */
	public function container(array $more = []): \Psr\Container\ContainerInterface {
		$services = array_merge(['OCA\\OpenRegister\\Service\\Form\\FormSubmitService' => $this], $more);

		return new class($services) implements \Psr\Container\ContainerInterface {
			/**
			 * @param array<string, object> $services The services.
			 */
			public function __construct(private array $services) {
			}

			public function get(string $id): mixed {
				if (isset($this->services[$id]) === false) {
					throw new class('not found: ' . $id) extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
					};
				}

				return $this->services[$id];
			}

			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};
	}//end container()

	/**
	 * A refusal shaped like OpenRegister's FormSubmitRefusedException.
	 *
	 * @param int                              $status   The status.
	 * @param array<int, array<string, mixed>> $findings The findings.
	 *
	 * @return \RuntimeException
	 */
	public static function refusal(int $status, array $findings = []): \RuntimeException {
		return new class('The form was not accepted.', $status, $findings) extends \RuntimeException {
			/**
			 * @param array<int, array<string, mixed>> $findings The findings.
			 */
			public function __construct(string $message, private int $refusedWith, private array $findings) {
				parent::__construct($message);
			}

			public function getStatus(): int {
				return $this->refusedWith;
			}

			public function getFindings(): array {
				return $this->findings;
			}
		};
	}//end refusal()
}//end class
