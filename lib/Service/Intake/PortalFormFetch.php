<?php

/**
 * Portaliq Portal Form Fetch (data-lookups-and-checks-in-forms, REQ-DIF-005)
 *
 * Runs the service fetches a form step declares through integriq, from the
 * server, so the browser never calls an outside service and never sees the
 * source, its credential or its raw answer.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalFormFetchInterface;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A step's fetch, asked of integriq's service fetch command.
 *
 * Integriq absent, an event nobody handled, a refusal and a dispatch that
 * throws are all the same answer: unavailable, so the step shows that the
 * data cannot be fetched now and the draft stays.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */
class PortalFormFetch implements PortalFormFetchInterface {
	/**
	 * The fetch ran and its outputs are in.
	 */
	public const FETCHED = 'fetched';

	/**
	 * The fetch did not answer.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Integriq's service fetch command, named as a string so portaliq does not
	 * depend on integriq being installed.
	 */
	public const FETCH_EVENT = 'OCA\\Integriq\\Event\\ServiceFetchRequestedEvent';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $events     Dispatches integriq's command.
	 * @param LoggerInterface  $logger     Logs a fetch that failed, without its values.
	 * @param string           $fetchEvent The command's class; a test names another one.
	 */
	public function __construct(
		private readonly IEventDispatcher $events,
		private readonly LoggerInterface $logger,
		private readonly string $fetchEvent = self::FETCH_EVENT,
	) {
	}//end __construct()

	/**
	 * The fetch a step declares under an id, or null when it declares none.
	 *
	 * @param array<int, array<string, mixed>> $steps The form's steps.
	 * @param string                           $step  The step's id.
	 * @param string                           $fetch The fetch's id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	public function declared(array $steps, string $step, string $fetch): ?array {
		foreach ($steps as $candidate) {
			if (is_array($candidate) === false || ($candidate['id'] ?? null) !== $step) {
				continue;
			}

			foreach ((array)($candidate['fetch'] ?? []) as $declared) {
				if (is_array($declared) === true && ($declared['id'] ?? null) === $fetch) {
					return $declared;
				}
			}
		}

		return null;
	}//end declared()

	/**
	 * Run one declared fetch with the answers so far.
	 *
	 * @param array<string, mixed> $fetch   The declared fetch.
	 * @param array<string, mixed> $answers The answers so far.
	 *
	 * @return array{status: string, values?: array<string, mixed>, check?: string|null, fetchedAt?: string, reason?: string}
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	public function run(array $fetch, array $answers): array {
		if (class_exists($this->fetchEvent) === false) {
			return ['status' => self::UNAVAILABLE, 'reason' => 'not_installed'];
		}

		$inputs = [];
		foreach ((array)($fetch['inputs'] ?? []) as $input => $field) {
			if (array_key_exists($field, $answers) === true) {
				$inputs[$input] = $answers[$field];
			}
		}

		$event = new ($this->fetchEvent)(app: AppIdentity::APP_ID, fetch: (string)($fetch['source'] ?? ''), inputs: $inputs);
		try {
			$this->events->dispatchTyped($event);
		} catch (Throwable $e) {
			// The message may quote an answer: log the class only.
			$this->logger->warning('Portaliq: form fetch request failed', ['exception' => get_class($e)]);
			return ['status' => self::UNAVAILABLE, 'reason' => 'dispatch_failed'];
		}

		return $this->answerOf(event: $event, fetch: $fetch);
	}//end run()

	/**
	 * The answers with every field a fetch fills taken from a fresh fetch, or
	 * null when one of the form's fetches did not answer.
	 *
	 * Runs on submit, so a value the browser changed or kept from another day
	 * never reaches a case: the field a fetch fills holds what the source says.
	 *
	 * @param array<int, array<string, mixed>> $steps   The form's steps.
	 * @param array<string, mixed>             $answers The validated answers.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	public function filled(array $steps, array $answers): ?array {
		foreach ($steps as $step) {
			if (is_array($step) === false) {
				continue;
			}

			foreach ((array)($step['fetch'] ?? []) as $fetch) {
				if (is_array($fetch) === false) {
					continue;
				}

				$answer = $this->run(fetch: $fetch, answers: $answers);
				if ($answer['status'] !== self::FETCHED) {
					return null;
				}

				$answers = array_merge($answers, ($answer['values'] ?? []));
			}
		}

		return $answers;
	}//end filled()

	/**
	 * Integriq's answer on the dispatched command, mapped onto the form.
	 *
	 * @param object               $event The dispatched command.
	 * @param array<string, mixed> $fetch The declared fetch.
	 *
	 * @return array{status: string, values?: array<string, mixed>, check?: string|null, fetchedAt?: string, reason?: string}
	 */
	private function answerOf(object $event, array $fetch): array {
		$result = $event->getResult();
		if (is_array($result) === false) {
			return ['status' => self::UNAVAILABLE, 'reason' => 'unhandled'];
		}

		$outputs = ($result['outputs'] ?? null);
		if (($result['ok'] ?? false) !== true || is_array($outputs) === false) {
			return ['status' => self::UNAVAILABLE, 'reason' => (string)($result['refusal'] ?? 'refused')];
		}

		$values = [];
		foreach ((array)($fetch['outputs'] ?? []) as $output => $field) {
			if (array_key_exists($output, $outputs) === true) {
				$values[$field] = $outputs[$output];
			}
		}

		return [
			'status' => self::FETCHED,
			'values' => $values,
			'check' => $this->checkLine(check: ($fetch['check'] ?? null), outputs: $outputs),
			'fetchedAt' => (string)($result['fetchedAt'] ?? ''),
		];
	}//end answerOf()

	/**
	 * The check line the declared output's value picks, or null.
	 *
	 * @param mixed                $check   The declared check.
	 * @param array<string, mixed> $outputs The fetch's outputs.
	 *
	 * @return string|null
	 */
	private function checkLine(mixed $check, array $outputs): ?string {
		if (is_array($check) === false || array_key_exists((string)($check['output'] ?? ''), $outputs) === false) {
			return null;
		}

		$value = $outputs[$check['output']];
		if (is_bool($value) === true) {
			$value = var_export($value, true);
		}

		if (is_scalar($value) === false) {
			return null;
		}

		$line = (((array)($check['lines'] ?? []))[(string)$value] ?? null);
		if (is_string($line) === false) {
			return null;
		}

		return $line;
	}//end checkLine()
}//end class
