<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake\Fakes;

use OCA\OpenRegister\Service\Form\FormSubmission;
use OCA\OpenRegister\Service\Form\FormSubmitter;
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
	 * OpenRegister's submit: one FormSubmission and the Idempotency-Key.
	 *
	 * Recorded flat (destination = the first write without its mapping) so the
	 * tests read what was asked, not how it was packed.
	 *
	 * @param FormSubmission $submission     The writes, payload and submitter.
	 * @param string|null    $idempotencyKey The key, when sent.
	 *
	 * @return array<string, mixed>
	 */
	public function submit(FormSubmission $submission, ?string $idempotencyKey = null): array {
		$this->calls[] = $this->flatten(submission: $submission) + ['idempotencyKey' => $idempotencyKey];
		if ($this->outcome instanceof Throwable) {
			throw $this->outcome;
		}

		return $this->outcome;
	}//end submit()

	/**
	 * OpenRegister's draft save: one FormSubmission.
	 *
	 * @param FormSubmission $submission The one write, payload and submitter.
	 *
	 * @return array<string, mixed>
	 */
	public function saveDraft(FormSubmission $submission): array {
		$this->calls[] = ['saveDraft' => true] + $this->flatten(submission: $submission);
		if ($this->outcome instanceof Throwable) {
			throw $this->outcome;
		}

		return $this->outcome;
	}//end saveDraft()

	/**
	 * OpenRegister's draft lookup: the destination pair, the submitter and the match.
	 *
	 * @param array<string, mixed> $destination The destination pair.
	 * @param FormSubmitter        $submitter   The subject and owner.
	 * @param array<string, mixed> $match       Values the draft must hold.
	 *
	 * @return array<string, mixed>|null
	 */
	public function findDraft(array $destination, FormSubmitter $submitter, array $match = []): ?array {
		$this->calls[] = ['findDraft' => true, 'destination' => $destination, 'subject' => $submitter->subject, 'owner' => $submitter->owner, 'match' => $match];
		if ($this->found instanceof Throwable) {
			throw $this->found;
		}

		return $this->found;
	}//end findDraft()

	/**
	 * A submission as the flat record the tests assert on.
	 *
	 * @param FormSubmission $submission The submission.
	 *
	 * @return array<string, mixed>
	 */
	private function flatten(FormSubmission $submission): array {
		$write   = ($submission->writes[0] ?? []);
		$mapping = ($write['mapping'] ?? null);
		unset($write['mapping']);

		return [
			'destination' => $write,
			'mapping' => $mapping,
			'writes' => $submission->writes,
			'payload' => $submission->payload,
			'subject' => $submission->subject,
			'scope' => $submission->scope,
			'draftId' => $submission->draftId,
			'owner' => $submission->owner,
		];
	}//end flatten()

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
