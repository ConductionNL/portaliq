<?php

/**
 * Portaliq Citizen Writable Set Resolver
 *
 * Answers one question: on THIS case, in its current status, for THIS
 * audience, which fields may the citizen change, which are closed and why,
 * whether documents may be added, and what the case app calls the status in
 * public.
 *
 * The portal keeps no writable list. Every answer here is read from the case
 * type the case points at, narrowed by the action's own `fields` whitelist, so
 * a case type can never widen what the contribution already granted. That
 * narrowing is defence in depth, not the rule: the rule is that the field
 * carries its own flag (D16) and every reader asks the field.
 *
 * Everything fails closed. No declaration, no case type, a malformed flag or a
 * status the type does not list all resolve to "not writable, and here is the
 * sentence", never to an open field.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\CitizenWriteConfigNormaliser;
use OCP\IL10N;

/**
 * Resolves the writable set, the amendment window and the public status label
 * for one case.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenWritableSetResolver {
	/**
	 * The property on the case type listing the fields a portal audience may
	 * change. Each entry IS the field's flag (D16).
	 */
	public const WRITABLE_PROPERTY = 'portalWritable';

	/**
	 * The property on the case type declaring the amendment window.
	 */
	public const WINDOW_PROPERTY = 'portalAmendmentWindow';

	/**
	 * The property on the case type declaring when documents may be added.
	 */
	public const DOCUMENTS_PROPERTY = 'portalDocumentWindow';

	/**
	 * The property on the case type carrying the public status vocabulary the
	 * portal renders unchanged.
	 */
	public const STATUS_LABELS_PROPERTY = 'portalStatusLabels';

	/**
	 * The case type property naming what the current status step offers next.
	 */
	public const STATUS_ACTIONS_PROPERTY = 'portalStatusActions';

	/**
	 * The property on the case type declaring whether an applicant may end
	 * their own request, until when, and onto which status.
	 *
	 * @spec openspec/changes/withdrawing-your-own-case-from-the-portal/specs/withdrawing-your-own-case/spec.md
	 */
	public const WITHDRAWAL_PROPERTY = 'portalWithdrawal';

	/**
	 * Constructor.
	 *
	 * @param CaseTypeReader $caseTypes Reads the case type the case points at.
	 * @param IL10N $l10n The sentence a closed field is shown with, when the
	 *                    case type supplies none of its own.
	 */
	public function __construct(
		private readonly CaseTypeReader $caseTypes,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Resolve the whole writable set for one case.
	 *
	 * @param array<string, mixed> $action The matched `type: update` action,
	 *                                     carrying its sanitised `citizenWrite`
	 *                                     declaration and its `fields` whitelist.
	 * @param array<string, mixed> $case The citizen's own case row.
	 * @param string $audience The session's audience, e.g. `client`.
	 * @param string $closedField The closed marker the case's collection
	 *                            declares (`closedField`), or ''.
	 *
	 * @return array<string, mixed> The writable set: `fields`, `writable`,
	 *                              `window`, `documents`, `status` and
	 *                              `ended`.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 * @spec openspec/changes/citizen-case-ended-shows-only-its-state/specs/citizen-writes-on-their-own-case/spec.md#requirement-a-case-that-has-ended-offers-nothing-and-explains-nothing
	 */
	public function resolve(array $action, array $case, string $audience, string $closedField = ''): array {
		$set = $this->resolveOpen(action: $action, case: $case, audience: $audience);
		if ((new CaseRowMarker())->hasEnded(row: $case, closedField: $closedField) === false) {
			return array_merge($set, ['ended' => false]);
		}

		// A withdrawn or closed case takes nothing more from the portal, and
		// the case type's sentences for a window that has closed ("is al in
		// behandeling, stuur ons een bericht") invite a resident to add to a
		// case that is over. Every window closes with one neutral sentence,
		// which a refused write answers with; the screen shows the case's
		// state instead (`ended`).
		$reason = $this->l10n->t('This case is not open for changes from the portal.');
		$closed = ['open' => false, 'reason' => $reason];
		$fields = [];
		foreach (array_keys((array)($set['fields'] ?? [])) as $field) {
			$fields[$field] = ['writable' => false, 'reason' => $reason];
		}

		return array_merge(
			$set,
			[
				'fields' => $fields,
				'writable' => [],
				'window' => $closed,
				'documents' => $closed,
				'ended' => true,
			]
		);
	}//end resolve()


	/**
	 * The writable set as the case type declares it for the case's status.
	 *
	 * @param array<string, mixed> $action The matched `type: update` action.
	 * @param array<string, mixed> $case The citizen's own case row.
	 * @param string $audience The session's audience.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	private function resolveOpen(array $action, array $case, string $audience): array {
		$config = ($action[CitizenWriteConfigNormaliser::KEY] ?? null);
		if (is_array($config) === false) {
			return $this->closedSet(reason: $this->l10n->t('This case is not open for changes from the portal.'));
		}

		$status = (string)($case[$config['statusField']] ?? '');
		$caseType = $this->caseTypes->readCaseType(
			register: (string)$config['typeRegister'],
			schema: (string)$config['typeSchema'],
			id: (string)($case[$config['typeField']] ?? '')
		);
		if ($caseType === null) {
			return $this->closedSet(reason: $this->l10n->t('This case is not open for changes from the portal.'));
		}

		$window = $this->window(caseType: $caseType, property: self::WINDOW_PROPERTY, status: $status);
		$documents = $this->window(caseType: $caseType, property: self::DOCUMENTS_PROPERTY, status: $status);
		$fields = $this->fields(
			caseType: $caseType,
			whitelist: $this->whitelist(action: $action),
			audience: $audience,
			status: $status,
			window: $window
		);

		$writable = [];
		foreach ($fields as $field => $state) {
			if (($state['writable'] ?? false) === true) {
				$writable[] = $field;
			}
		}

		return [
			'fields' => $fields,
			'writable' => $writable,
			'window' => $window,
			'documents' => $documents,
			'status' => $this->status(caseType: $caseType, status: $status),
		];
	}//end resolveOpen()

	/**
	 * Whether this case may be withdrawn right now, and onto what.
	 *
	 * The portal keeps no list of withdrawable case types: a type that says
	 * nothing offers nothing, and a window that has closed yields the reason
	 * rather than an action that would fail.
	 *
	 * @param array<string, mixed> $action The matched `type: update` action.
	 * @param array<string, mixed> $case The citizen's own case row.
	 * @param string $closedField The closed marker the case's collection
	 *                            declares (`closedField`), or ''.
	 *
	 * @return array<string, mixed> `declared`, `open`, `reason`,
	 *         `targetStatus` and `confirmText`.
	 *
	 * @spec openspec/changes/withdrawing-your-own-case-from-the-portal/specs/withdrawing-your-own-case/spec.md
	 * @spec openspec/changes/citizen-case-ended-shows-only-its-state/specs/citizen-writes-on-their-own-case/spec.md#requirement-a-case-that-has-ended-offers-nothing-and-explains-nothing
	 */
	public function withdrawal(array $action, array $case, string $closedField = ''): array {
		$closed = [
			'declared' => false,
			'open' => false,
			'reason' => $this->l10n->t('This request cannot be withdrawn from the portal.'),
			'targetStatus' => '',
			'confirmText' => '',
		];

		$config = ($action[CitizenWriteConfigNormaliser::KEY] ?? null);
		if (is_array($config) === false) {
			return $closed;
		}

		$caseType = $this->caseTypes->readCaseType(
			register: (string)$config['typeRegister'],
			schema: (string)$config['typeSchema'],
			id: (string)($case[$config['typeField']] ?? '')
		);
		if ($caseType === null) {
			return $closed;
		}

		$declared = ($caseType[self::WITHDRAWAL_PROPERTY] ?? null);
		if (is_array($declared) === false) {
			return $closed;
		}

		$targetStatus = (string)($declared['targetStatus'] ?? '');
		if ($targetStatus === '') {
			// A declaration with nowhere to land is not a declaration: the
			// portal will not invent the status a withdrawal lands on.
			return $closed;
		}

		$status = (string)($case[$config['statusField']] ?? '');
		$window = $this->window(caseType: $caseType, property: self::WITHDRAWAL_PROPERTY, status: $status);
		$confirmText = (string)($declared['confirmText'] ?? '');

		if ($status === $targetStatus) {
			// Already withdrawn. Not an error yet, but never open again.
			return [
				'declared' => true,
				'open' => false,
				'reason' => $this->l10n->t('This request has already been withdrawn.'),
				'targetStatus' => $targetStatus,
				'confirmText' => $confirmText,
			];
		}

		if ((new CaseRowMarker())->hasEnded(row: $case, closedField: $closedField) === true) {
			// A closed case is not withdrawn: there is nothing left to stop.
			return [
				'declared' => true,
				'open' => false,
				'reason' => $this->l10n->t('This request cannot be withdrawn from the portal.'),
				'targetStatus' => $targetStatus,
				'confirmText' => $confirmText,
			];
		}

		return [
			'declared' => true,
			'open' => ($window['open'] === true),
			'reason' => (string)$window['reason'],
			'targetStatus' => $targetStatus,
			'confirmText' => $confirmText,
		];
	}//end withdrawal()

	/**
	 * Whether one field may be written right now, without rebuilding the whole
	 * set. The refusal path calls this, so the answer a write is judged by and
	 * the answer the citizen was shown come from the same code.
	 *
	 * @param array<string, mixed> $set A resolved set from `resolve()`.
	 * @param string $field The field a write names.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function isWritable(array $set, string $field): bool {
		return in_array($field, (array)($set['writable'] ?? []), true);
	}//end isWritable()

	/**
	 * The action's own `fields` whitelist, as strings.
	 *
	 * @param array<string, mixed> $action The matched action.
	 *
	 * @return array<int, string>
	 */
	private function whitelist(array $action): array {
		$fields = ($action['fields'] ?? null);
		if (is_array($fields) === false) {
			return [];
		}

		return array_values(array_filter($fields, static fn ($field) => is_string($field) === true && $field !== ''));
	}//end whitelist()

	/**
	 * Resolve every declared field flag against this audience and status.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 * @param array<int, string> $whitelist The action's `fields`.
	 * @param string $audience The session's audience.
	 * @param string $status The case's current status.
	 * @param array<string, mixed> $window The resolved amendment window.
	 *
	 * @return array<string, array<string, mixed>> Per field: writable, reason.
	 */
	private function fields(array $caseType, array $whitelist, string $audience, string $status, array $window): array {
		$declared = ($caseType[self::WRITABLE_PROPERTY] ?? null);
		$fields = [];
		if (is_array($declared) === false) {
			return $fields;
		}

		foreach ($declared as $entry) {
			$field = null;
			if (is_array($entry) === true) {
				$field = ($entry['field'] ?? null);
			}

			if (is_string($field) === false || $field === '' || in_array($field, $whitelist, true) === false) {
				continue;
			}

			$fields[$field] = $this->fieldState(
				entry: $entry,
				audience: $audience,
				status: $status,
				window: $window
			);
		}//end foreach

		return $fields;
	}//end fields()

	/**
	 * One field flag's state. A flag that does not name this audience is not a
	 * closed field, it is no flag at all, so it reads closed with the window's
	 * own sentence rather than a field-specific one.
	 *
	 * @param array<string, mixed> $entry The declared flag.
	 * @param string $audience The session's audience.
	 * @param string $status The case's current status.
	 * @param array<string, mixed> $window The resolved amendment window.
	 *
	 * @return array<string, mixed>
	 */
	private function fieldState(array $entry, string $audience, string $status, array $window): array {
		$audiences = ($entry['audiences'] ?? null);
		if (is_array($audiences) === false || in_array($audience, $audiences, true) === false) {
			return ['writable' => false, 'reason' => $this->reasonOf(entry: $entry, window: $window)];
		}

		// The window closes everything at once: an open field inside a closed
		// window would let an amendment land after the case type said it may not.
		if (($window['open'] ?? false) !== true) {
			return ['writable' => false, 'reason' => (string)($window['reason'] ?? '')];
		}

		$openStatuses = ($entry['openStatuses'] ?? null);
		if (is_array($openStatuses) === true && in_array($status, $openStatuses, true) === false) {
			return ['writable' => false, 'reason' => $this->reasonOf(entry: $entry, window: $window)];
		}

		return ['writable' => true, 'reason' => ''];
	}//end fieldState()

	/**
	 * The sentence a closed field is shown with: the field's own, else the
	 * window's, else the portal's fallback. Never a blank disabled control.
	 *
	 * @param array<string, mixed> $entry The declared flag.
	 * @param array<string, mixed> $window The resolved amendment window.
	 *
	 * @return string
	 */
	private function reasonOf(array $entry, array $window): string {
		$own = ($entry['closedReason'] ?? null);
		if (is_string($own) === true && $own !== '') {
			return $own;
		}

		$fromWindow = (string)($window['reason'] ?? '');
		if ($fromWindow !== '') {
			return $fromWindow;
		}

		return $this->l10n->t('This answer can no longer be changed.');
	}//end reasonOf()

	/**
	 * Resolve one declared window against the case's status. An absent or
	 * malformed declaration is a closed window.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 * @param string $property The window property to read.
	 * @param string $status The case's current status.
	 *
	 * @return array<string, mixed> `open` and `reason`.
	 */
	private function window(array $caseType, string $property, string $status): array {
		$declared = ($caseType[$property] ?? null);
		if (is_array($declared) === false) {
			return ['open' => false, 'reason' => $this->l10n->t('This case is not open for changes from the portal.')];
		}

		$reason = ($declared['closedReason'] ?? null);
		if (is_string($reason) === false || $reason === '') {
			$reason = $this->l10n->t('This case is not open for changes from the portal.');
		}

		$openStatuses = ($declared['openStatuses'] ?? null);
		if (is_array($openStatuses) === false || in_array($status, $openStatuses, true) === false) {
			return ['open' => false, 'reason' => $reason];
		}

		return ['open' => true, 'reason' => ''];
	}//end window()

	/**
	 * The public status label the case app supplied, rendered as given. The
	 * portal holds no vocabulary of its own, so an unlabelled status yields
	 * empty strings rather than a portal-invented word.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 * @param string $status The case's current status.
	 *
	 * @return array<string, string> `value`, `label` and `description`.
	 */
	private function status(array $caseType, string $status): array {
		$labels = ($caseType[self::STATUS_LABELS_PROPERTY] ?? null);
		$entry = null;
		if (is_array($labels) === true) {
			$entry = ($labels[$status] ?? null);
		}

		$extra = [
			'action' => $this->statusAction(caseType: $caseType, status: $status),
			'next'   => $this->nextStatus(labels: $labels, status: $status),
		];
		if (is_array($entry) === false) {
			return ['value' => $status, 'label' => '', 'description' => ''] + $extra;
		}

		$label = ($entry['label'] ?? '');
		$description = ($entry['description'] ?? '');

		if (is_string($label) === false) {
			$label = '';
		}

		if (is_string($description) === false) {
			$description = '';
		}

		return ['value' => $status, 'label' => $label, 'description' => $description] + $extra;
	}//end status()

	/**
	 * The button the current status offers, or null when its entry is missing or malformed.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 * @param string $status The case's current status.
	 *
	 * @return array{label: string, kind: string, target: string}|null
	 *
	 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t04
	 */
	private function statusAction(array $caseType, string $status): ?array {
		$actions = ($caseType[self::STATUS_ACTIONS_PROPERTY] ?? null);
		$entry   = null;
		if (is_array($actions) === true) {
			$entry = ($actions[$status] ?? null);
		}

		if (is_array($entry) === false) {
			return null;
		}

		$label  = ($entry['label'] ?? null);
		$kind   = ($entry['kind'] ?? null);
		$target = ($entry['target'] ?? null);
		if (is_string($label) === false || trim($label) === '' || in_array($kind, ['task', 'page', 'action'], true) === false
			|| is_string($target) === false || trim($target) === ''
		) {
			return null;
		}

		return ['label' => trim($label), 'kind' => $kind, 'target' => trim($target)];
	}//end statusAction()

	/**
	 * The status after the current one, in the order the case type lists its labels.
	 *
	 * @param mixed $labels The case type's status labels.
	 * @param string $status The case's current status.
	 *
	 * @return array{value: string, label: string}|null
	 *
	 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t04
	 */
	private function nextStatus(mixed $labels, string $status): ?array {
		if (is_array($labels) === false) {
			return null;
		}

		$keys     = array_map('strval', array_keys($labels));
		$position = array_search($status, $keys, true);
		if ($position === false || isset($keys[($position + 1)]) === false) {
			return null;
		}

		$value = $keys[($position + 1)];
		$entry = $labels[$value];
		$label = '';
		if (is_array($entry) === true && is_string($entry['label'] ?? null) === true) {
			$label = $entry['label'];
		}

		if ($label === '') {
			return null;
		}

		return ['value' => $value, 'label' => $label];
	}//end nextStatus()

	/**
	 * The set a case with no reachable declaration resolves to: nothing
	 * writable, both windows closed, no status label invented.
	 *
	 * @param string $reason The sentence to show.
	 *
	 * @return array<string, mixed>
	 */
	private function closedSet(string $reason): array {
		return [
			'fields' => [],
			'writable' => [],
			'window' => ['open' => false, 'reason' => $reason],
			'documents' => ['open' => false, 'reason' => $reason],
			'status' => ['value' => '', 'label' => '', 'description' => '', 'action' => null, 'next' => null],
		];
	}//end closedSet()
}//end class
