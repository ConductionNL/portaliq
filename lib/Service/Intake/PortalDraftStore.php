<?php

/**
 * Portaliq Portal Draft Store
 *
 * Keeps the half-filled answers of a create or endpoint action for the
 * signed-in resident who typed them. A draft belongs to portaliq: it is never
 * sent to the contributing app, it is readable only by its subject, and it is
 * deleted when the action is sent or when its retention runs out.
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
 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * Saves, reads, deletes and purges the drafts of an action.
 *
 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 */
class PortalDraftStore {
	/**
	 * The shortest retention a draft may be given, in days.
	 */
	public const MIN_DAYS = 1;

	/**
	 * The longest retention a draft may be given, in days.
	 */
	public const MAX_DAYS = 90;

	/**
	 * The register the draft lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a draft.
	 */
	private const SCHEMA = 'portalDraft';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the drafts.
	 * @param PortalObjectWriter $writer Writes and deletes the drafts.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
	) {
	}//end __construct()

	/**
	 * Save the draft of one subject and action, replacing an earlier one.
	 *
	 * @param string $subjectRef The signed-in subject. Empty saves nothing.
	 * @param string $actionKey The contribution and action, `app/action`.
	 * @param array<string, mixed> $answers The visible answers.
	 * @param string $step The step the resident reached.
	 * @param int $retentionDays How long the draft is kept, clamped to 1 to 90.
	 * @param DateTimeImmutable|null $now The clock, for tests.
	 *
	 * @return array{step: string, expiresAt: string, answers: array<string, mixed>}|null Null when nothing was saved.
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
	 */
	public function save(string $subjectRef, string $actionKey, array $answers, string $step, int $retentionDays, ?DateTimeImmutable $now = null): ?array {
		if ($subjectRef === '' || $actionKey === '') {
			return null;
		}

		$days = max(self::MIN_DAYS, min(self::MAX_DAYS, $retentionDays));
		$expiresAt = ($now ?? new DateTimeImmutable())->modify('+' . $days . ' days')->format(DATE_ATOM);
		$answers = self::withoutFiles(answers: $answers);
		$data = [
			'kind' => 'draft',
			'subjectRef' => $subjectRef,
			'actionKey' => $actionKey,
			'answers' => $answers,
			'step' => $step,
			'expiresAt' => $expiresAt,
		];

		$existing = $this->find(subjectRef: $subjectRef, actionKey: $actionKey);
		if ($existing !== null) {
			$saved = $this->writer->updateObject(
				register: self::REGISTER,
				schema: self::SCHEMA,
				scopeField: 'subjectRef',
				subjectRef: $subjectRef,
				organisation: '',
				id: self::idOf(row: $existing),
				data: $data
			);
		} else {
			$saved = $this->writer->createObject(
				register: self::REGISTER,
				schema: self::SCHEMA,
				scopeField: 'subjectRef',
				subjectRef: $subjectRef,
				organisation: '',
				data: $data
			);
		}

		if ($saved === null) {
			return null;
		}

		return ['step' => $step, 'expiresAt' => $expiresAt, 'answers' => $answers];
	}//end save()

	/**
	 * The subject's own draft of an action, unless it has expired.
	 *
	 * @param string $subjectRef The signed-in subject.
	 * @param string $actionKey The contribution and action, `app/action`.
	 * @param DateTimeImmutable|null $now The clock, for tests.
	 *
	 * @return array{step: string, expiresAt: string, answers: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
	 */
	public function read(string $subjectRef, string $actionKey, ?DateTimeImmutable $now = null): ?array {
		$row = $this->find(subjectRef: $subjectRef, actionKey: $actionKey);
		if ($row === null || self::expired(row: $row, now: $now ?? new DateTimeImmutable()) === true) {
			return null;
		}

		return [
			'step' => (string)($row['step'] ?? ''),
			'expiresAt' => (string)($row['expiresAt'] ?? ''),
			'answers' => (array)($row['answers'] ?? []),
		];
	}//end read()

	/**
	 * Delete the subject's draft of an action, as when the action was sent.
	 *
	 * @param string $subjectRef The signed-in subject.
	 * @param string $actionKey The contribution and action, `app/action`.
	 *
	 * @return bool True when a draft existed and is gone.
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
	 */
	public function discard(string $subjectRef, string $actionKey): bool {
		$row = $this->find(subjectRef: $subjectRef, actionKey: $actionKey);
		if ($row === null) {
			return false;
		}

		return $this->remove(row: $row);
	}//end discard()

	/**
	 * Delete the drafts whose retention has passed.
	 *
	 * @param int $limit How many drafts one run looks at.
	 * @param DateTimeImmutable|null $now The clock, for tests.
	 *
	 * @return int How many were deleted.
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
	 */
	public function purgeExpired(int $limit = 200, ?DateTimeImmutable $now = null): int {
		$now ??= new DateTimeImmutable();
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'kind',
			subjectRef: 'draft',
			organisation: '',
			limit: $limit
		);

		$deleted = 0;
		foreach ($rows as $row) {
			if (is_array($row) === true && self::expired(row: $row, now: $now) === true && $this->remove(row: $row) === true) {
				$deleted++;
			}
		}

		return $deleted;
	}//end purgeExpired()

	/**
	 * Drop the answers a draft must not keep: uploaded files.
	 *
	 * @param array<string, mixed> $answers The answers as typed.
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutFiles(array $answers): array {
		$kept = [];
		foreach ($answers as $key => $value) {
			if (is_array($value) === true && (isset($value['tmp_name']) === true || isset($value['fileName']) === true || isset($value['contentBase64']) === true)) {
				continue;
			}

			$kept[$key] = $value;
		}

		return $kept;
	}//end withoutFiles()

	/**
	 * The subject's row for an action.
	 *
	 * @param string $subjectRef The subject.
	 * @param string $actionKey The contribution and action.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find(string $subjectRef, string $actionKey): ?array {
		if ($subjectRef === '' || $actionKey === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: '',
			limit: 50,
			filter: ['actionKey' => $actionKey]
		);

		foreach ($rows as $row) {
			// Re-checked here: the owner and the action decide, not the query.
			if (is_array($row) === true && ($row['subjectRef'] ?? '') === $subjectRef && ($row['actionKey'] ?? '') === $actionKey) {
				return $row;
			}
		}

		return null;
	}//end find()

	/**
	 * Delete one row through its owner.
	 *
	 * @param array<string, mixed> $row The draft row.
	 *
	 * @return bool
	 */
	private function remove(array $row): bool {
		$id = self::idOf(row: $row);
		if ($id === '') {
			return false;
		}

		return $this->writer->deleteObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: (string)($row['subjectRef'] ?? ''),
			organisation: '',
			id: $id
		);
	}//end remove()

	/**
	 * Whether a draft's retention has passed. A row without a date is expired:
	 * a draft that cannot say when it ends is not kept.
	 *
	 * @param array<string, mixed> $row The draft row.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return bool
	 */
	private static function expired(array $row, DateTimeImmutable $now): bool {
		$expiresAt = (string)($row['expiresAt'] ?? '');
		if ($expiresAt === '') {
			return true;
		}

		$ts = strtotime($expiresAt);
		return $ts === false || $ts <= $now->getTimestamp();
	}//end expired()

	/**
	 * The uuid of a stored row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		$id = (string)($row['uuid'] ?? $row['id'] ?? '');
		if ($id === '') {
			$self = (array)($row['@self'] ?? []);
			$id = (string)($self['uuid'] ?? $self['id'] ?? '');
		}

		return $id;
	}//end idOf()
}//end class
