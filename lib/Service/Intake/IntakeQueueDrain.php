<?php

/**
 * Portaliq Intake Queue Drain
 *
 * Sends the requests still waiting in the old intake queue into the
 * destination their form names, through the same submit every new request
 * takes, and reports what arrived and what was refused (decision 179).
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
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * Drains `queued` and `failed` queue entries into their destinations.
 *
 * The old reference rides along as `externalReference`, so a resident's
 * earlier link still finds the object (decision 181, Q10). It is also the
 * Idempotency-Key, so a second run, or a run cut off half way, never makes
 * a second object for one request.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
 */
class IntakeQueueDrain {
	/**
	 * Constructor.
	 *
	 * @param PortalIntakeQueue         $queue    The entries waiting.
	 * @param PortalFormBindingResolver $bindings The form each entry came from.
	 * @param DestinationIntake         $intake   Submits into the destination.
	 */
	public function __construct(
		private readonly PortalIntakeQueue $queue,
		private readonly PortalFormBindingResolver $bindings,
		private readonly DestinationIntake $intake,
	) {
	}//end __construct()

	/**
	 * One pass over the waiting entries.
	 *
	 * @param int $limit How many entries of each state to take.
	 *
	 * @return array{delivered: int, refused: int, waiting: int, reasons: array<string, string>}
	 *         `reasons` holds why each refused entry did not arrive, by reference.
	 *         `waiting` is what is left after the pass; zero means the queue may go (task 4.3).
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	public function drain(int $limit = 200): array {
		$pending   = $this->queue->pending(limit: $limit);
		$delivered = 0;
		$reasons   = [];
		foreach ($pending as $entry) {
			$reason = $this->deliver(entry: $entry);
			if ($reason === null) {
				$delivered++;
				continue;
			}

			$reasons[(string)($entry['reference'] ?? '')] = $reason;
		}

		return [
			'delivered' => $delivered,
			'refused' => count($reasons),
			'waiting' => count($this->queue->pending(limit: $limit)),
			'reasons' => $reasons,
		];
	}//end drain()

	/**
	 * How many entries wait, sending nothing.
	 *
	 * @param int $limit How many entries of each state to count.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	public function waiting(int $limit = 200): int {
		return count($this->queue->pending(limit: $limit));
	}//end waiting()

	/**
	 * Send one entry, and mark it.
	 *
	 * @param array<string, mixed> $entry The queue entry.
	 *
	 * @return string|null Why it did not arrive, or null when it did.
	 */
	private function deliver(array $entry): ?string {
		$binding = $this->bindings->bindingFor(portal: (string)($entry['portal'] ?? ''), route: (string)($entry['route'] ?? ''));
		if ($binding === null) {
			return $this->refuse(entry: $entry, reason: 'The form this request came from is no longer published.');
		}

		$reference = (string)($entry['reference'] ?? '');

		$submitted = $this->intake->submit(
			binding: $binding,
			render: $this->bindings->render(binding: $binding),
			answers: (array)($entry['answers'] ?? []),
			subjectRef: (string)($entry['subjectRef'] ?? ''),
			server: [
				'externalReference' => $reference,
				'portal' => (string)($entry['portal'] ?? ''),
				'origin' => (string)($entry['origin'] ?? ''),
				'statements' => (array)($entry['statements'] ?? []),
				'decisions' => (array)($entry['decisions'] ?? []),
				'verifiedEmails' => (array)($entry['verifiedEmails'] ?? []),
				'fieldMeta' => (new DestinationMapping())->fieldMeta(computed: (array)($entry['computed'] ?? [])),
			],
			idempotencyKey: $reference
		);

		if ($submitted['ok'] === false) {
			$reason = $submitted['message'];
			if ($submitted['errors'] !== []) {
				$reason .= ' ' . json_encode($submitted['errors']);
			}

			return $this->refuse(entry: $entry, reason: trim($reason));
		}

		$answer = $submitted['answer'];
		$this->queue->markRegistered(
			submission: $entry,
			caseId: (string)($answer['id'] ?? ''),
			externalReference: (string)($answer['reference'] ?? ''),
			dueAt: $this->dueAt(confirmation: (array)($answer['confirmation'] ?? []))
		);

		return null;
	}//end deliver()

	/**
	 * Mark an entry failed with the reason, and answer the reason.
	 *
	 * @param array<string, mixed> $entry  The entry.
	 * @param string               $reason Why.
	 *
	 * @return string
	 */
	private function refuse(array $entry, string $reason): string {
		$this->queue->markFailed(submission: $entry, reason: $reason);

		return $reason;
	}//end refuse()

	/**
	 * The deadline as the queue's `dueAt` date-time, or '' when there is none.
	 *
	 * @param array<string, mixed> $confirmation The destination's confirmation properties.
	 *
	 * @return string
	 */
	private function dueAt(array $confirmation): string {
		$deadline = (string)($confirmation['deadline'] ?? '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) === 1) {
			return $deadline . 'T00:00:00+00:00';
		}

		return '';
	}//end dueAt()
}//end class
