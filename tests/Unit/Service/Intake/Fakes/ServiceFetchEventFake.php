<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake\Fakes;

use OCP\EventDispatcher\Event;

/**
 * Stands in for integriq's ServiceFetchRequestedEvent, with its constructor
 * (app, fetch, inputs) and its getResult() shape: `{ok: true, outputs,
 * fetchedAt}`, `{ok: false, refusal, detail}`, or null when nothing answered.
 */
class ServiceFetchEventFake extends Event {

	/**
	 * The result a listener set, or null while unhandled.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Constructor.
	 *
	 * @param string               $app    The calling app's id.
	 * @param string               $fetch  The service fetch's slug.
	 * @param array<string, mixed> $inputs The input values, by input name.
	 */
	public function __construct(
		public readonly string $app,
		public readonly string $fetch,
		public readonly array $inputs = [],
	) {
		parent::__construct();
	}

	/**
	 * Answer with outputs, as integriq's listener does.
	 *
	 * @param array<string, mixed> $outputs   The outputs.
	 * @param string               $fetchedAt When they were fetched.
	 *
	 * @return void
	 */
	public function setOutputs(array $outputs, string $fetchedAt): void {
		$this->result = ['ok' => true, 'outputs' => $outputs, 'fetchedAt' => $fetchedAt];
	}

	/**
	 * Answer with a refusal, as integriq's listener does.
	 *
	 * @param string $refusal The refusal code.
	 *
	 * @return void
	 */
	public function refuse(string $refusal): void {
		$this->result = ['ok' => false, 'refusal' => $refusal, 'detail' => null];
	}

	/**
	 * The result, or null when nothing answered.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getResult(): ?array {
		return $this->result;
	}
}
