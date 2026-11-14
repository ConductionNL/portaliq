<?php

/**
 * Portaliq Portal Draft Drain
 *
 * Moves the drafts portaliq kept in its own `portalDraft` schema into
 * OpenRegister drafts: the destination object of the draft's create action,
 * in status `draft`, owned by `portal:<subjectRef>` (decisions 180 and 195).
 * A row is deleted only after its draft was saved. A row whose answers the
 * destination refuses as type-invalid, or whose action no longer exists for
 * its subject, is reported and kept, never converted by guessing. A row past
 * its own retention is deleted unconverted.
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
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#5.3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Kernel\Contracts\PortalDraftDrainInterface;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;

/**
 * Drains `portalDraft` rows into OpenRegister drafts.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#5.3
 */
class PortalDraftDrain implements PortalDraftDrainInterface {
	/**
	 * Where portaliq kept its drafts.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema being drained.
	 */
	private const SCHEMA = 'portalDraft';

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface $reader  Reads the rows.
	 * @param PortalObjectWriter $writer  Deletes a drained row.
	 * @param ActionDraft        $actions Resolves the row's action and the draft body.
	 * @param DestinationDraft   $drafts  Saves the OpenRegister draft.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ActionDraft $actions,
		private readonly DestinationDraft $drafts,
	) {
	}//end __construct()

	/**
	 * Drain up to `$limit` rows.
	 *
	 * @param int                    $limit How many rows to read.
	 * @param DateTimeImmutable|null $now   The moment, for tests.
	 *
	 * @return array{converted: int, expired: int, refused: int, reasons: array<string, string>, waiting: int}
	 *         `waiting` counts the rows still there afterwards.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#5.3
	 */
	public function drain(int $limit = 200, ?DateTimeImmutable $now = null): array {
		$now    = ($now ?? new DateTimeImmutable());
		$result = ['converted' => 0, 'expired' => 0, 'refused' => 0, 'reasons' => [], 'waiting' => 0];
		foreach ($this->rows(limit: $limit) as $row) {
			$outcome = $this->drainOne(row: $row, now: $now);
			if (in_array($outcome, ['converted', 'expired'], true) === true) {
				$result[$outcome]++;
				continue;
			}

			$result['refused']++;
			$result['reasons'][self::idOf(row: $row)] = $outcome;
		}

		$result['waiting'] = $this->waiting(limit: $limit);
		return $result;
	}//end drain()

	/**
	 * How many rows wait to be drained.
	 *
	 * @param int $limit How many rows to read.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#5.3
	 */
	public function waiting(int $limit = 200): int {
		return count($this->rows(limit: $limit));
	}//end waiting()

	/**
	 * Drain one row.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param DateTimeImmutable    $now The moment.
	 *
	 * @return string `converted`, `expired`, or the reason it was kept.
	 */
	private function drainOne(array $row, DateTimeImmutable $now): string {
		if ($this->isExpired(row: $row, now: $now) === true) {
			return $this->remove(row: $row, outcome: 'expired');
		}

		$match = $this->matchOf(row: $row);
		if ($match === null) {
			return 'its action is not a create action this subject can use';
		}

		$reason = $this->convert(draft: ['row' => $row, 'match' => $match, 'subjectRef' => (string)($row['subjectRef'] ?? '')], now: $now);
		if ($reason !== null) {
			return $reason;
		}

		return $this->remove(row: $row, outcome: 'converted');
	}//end drainOne()

	/**
	 * Whether a row's expiry has passed; a row without a readable expiry never expires.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param DateTimeImmutable    $now The moment.
	 *
	 * @return bool
	 */
	private function isExpired(array $row, DateTimeImmutable $now): bool {
		$expiresAt = strtotime((string)($row['expiresAt'] ?? ''));

		return $expiresAt !== false && $expiresAt <= $now->getTimestamp();
	}//end isExpired()

	/**
	 * The create action a row's `app/action` key names, when its subject may use it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function matchOf(array $row): ?array {
		$subjectRef = (string)($row['subjectRef'] ?? '');
		$parts      = explode('/', (string)($row['actionKey'] ?? ''), 2);
		if ($subjectRef === '' || count($parts) !== 2) {
			return null;
		}

		return $this->actions->actionFor(subject: ['subjectRef' => $subjectRef], appId: $parts[0], actionId: $parts[1]);
	}//end matchOf()

	/**
	 * Save a row as an OpenRegister draft of its action's destination.
	 *
	 * @param array{row: array<string, mixed>, match: array<string, mixed>, subjectRef: string} $draft The row, its action and its subject.
	 * @param DateTimeImmutable                                                                $now   The moment.
	 *
	 * @return string|null Why it was kept, or null when it was saved.
	 */
	private function convert(array $draft, DateTimeImmutable $now): ?string {
		$answers = (array)($draft['row']['answers'] ?? []);
		$body    = $this->actions->bodyOf(match: $draft['match'], subject: ['subjectRef' => $draft['subjectRef']], answers: $answers);
		if ($body === null) {
			return 'its scope claim does not resolve';
		}

		$saved = $this->save(draft: $draft + ['body' => $body], now: $now);
		if ($saved['ok'] === false) {
			return 'the destination refused it (' . $saved['status'] . '): ' . implode('; ', $this->drafts->errorsOf(findings: $saved['findings']));
		}

		return null;
	}//end convert()

	/**
	 * Save the draft body over the subject's existing draft of the action, if any.
	 *
	 * @param array{match: array<string, mixed>, subjectRef: string, body: array<string, mixed>} $draft The action, its subject and the body.
	 * @param DateTimeImmutable                                                                  $now   The moment.
	 *
	 * @return array<string, mixed> The destination's answer.
	 */
	private function save(array $draft, DateTimeImmutable $now): array {
		$existing = $this->actions->find(action: $draft['match']['action'], subjectRef: $draft['subjectRef'], now: $now);

		return $this->drafts->saveDraft(
			destination: $this->actions->destinationOf(action: $draft['match']['action']),
			payload: $draft['body'],
			subjectRef: $draft['subjectRef'],
			draftId: (string)($existing['id'] ?? '')
		);
	}//end save()

	/**
	 * Delete a row and answer the outcome, or say it could not be deleted.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param string               $outcome What happened to it.
	 *
	 * @return string
	 */
	private function remove(array $row, string $outcome): string {
		$deleted = $this->writer->deleteObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: (string)($row['subjectRef'] ?? ''),
			organisation: '',
			id: self::idOf(row: $row)
		);
		if ($deleted === false) {
			return 'it was ' . $outcome . ' but the row could not be deleted';
		}

		return $outcome;
	}//end remove()

	/**
	 * The rows.
	 *
	 * @param int $limit How many to read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(int $limit): array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'kind',
			subjectRef: 'draft',
			organisation: '',
			limit: $limit
		);

		return array_values(array_filter($rows, 'is_array'));
	}//end rows()

	/**
	 * A row's id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		return (string)($row['uuid'] ?? $row['id'] ?? $self['uuid'] ?? $self['id'] ?? '');
	}//end idOf()
}//end class
