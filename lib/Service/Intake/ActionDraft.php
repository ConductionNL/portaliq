<?php

/**
 * Portaliq Action Draft
 *
 * A resident's saved answers of a create action are a draft of the object
 * that action creates: the destination object itself, in OpenRegister status
 * `draft` (decision 180), owned by `portal:<subjectRef>` (decision 195).
 * This class answers the questions the draft endpoints and the create path
 * share: which action, where it writes, what a draft holds, and whether a
 * draft is still within its retention (decision 201: the owning app's
 * retention, 30 days by default, counted from the draft's last change).
 *
 * Generic (decision 182): it reads the action's declaration only, never an
 * app, a case type or a procedure.
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
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Contribution\ActionScopeResolver;
use OCA\Portaliq\Contribution\CreateBody;
use OCA\Portaliq\Contribution\FieldMap;
use OCA\Portaliq\Contribution\FileFieldConfigNormaliser;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;
use Throwable;

/**
 * Resolves a create action's draft: its action, destination, body and retention.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies, the one trust rule every portal path uses.
 */
class ActionDraft {
	/**
	 * Retention when the action declares none (decision 201).
	 */
	public const DEFAULT_DAYS = 30;

	/**
	 * The shortest retention an action may declare.
	 */
	public const MIN_DAYS = 1;

	/**
	 * The longest retention an action may declare.
	 */
	public const MAX_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param PortalContributionRegistry $registry The subject's aggregate of actions.
	 * @param PortalObjectReader         $reader   Resolves the action's scope claim.
	 * @param PortalObjectWriter         $writer   Applies the create's ownership stamps.
	 * @param DestinationDraft           $drafts   Finds and discards drafts in OpenRegister.
	 */
	public function __construct(
		private readonly PortalContributionRegistry $registry,
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly DestinationDraft $drafts,
	) {
	}//end __construct()

	/**
	 * The subject's create action with this id in this app, or null.
	 *
	 * @param array<string, mixed> $subject  The resolved subject.
	 * @param string               $appId    The contributing app.
	 * @param string               $actionId The action.
	 *
	 * @return array{action: array<string, mixed>, app: string}|null Null when the action is unknown,
	 *         is not a create with a register and schema, or asks more trust than the subject has.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function actionFor(array $subject, string $appId, string $actionId): ?array {
		foreach (($this->registry->aggregateFor($subject)['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false || ($contribution['app'] ?? '') !== $appId) {
				continue;
			}

			foreach ((array)($contribution['actions'] ?? []) as $action) {
				if (is_array($action) === true && ($action['id'] ?? '') === $actionId && $this->isDraftable(action: $action, subject: $subject) === true) {
					return ['action' => $action, 'app' => $appId];
				}
			}
		}

		return null;
	}//end actionFor()

	/**
	 * Where the action's draft lives: the register and schema it creates in.
	 *
	 * @param array<string, mixed> $action The create action.
	 *
	 * @return array{register: string, schema: string}
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function destinationOf(array $action): array {
		return ['register' => (string)($action['register'] ?? ''), 'schema' => (string)($action['schema'] ?? '')];
	}//end destinationOf()

	/**
	 * What a draft of the action holds: the declared, non-file answers, the
	 * action's defaults, and the same ownership stamps a create writes.
	 *
	 * @param array{action: array<string, mixed>, app: string} $match   The action and its app.
	 * @param array<string, mixed>                             $subject The resolved subject.
	 * @param array<string, mixed>                             $answers The answers sent.
	 *
	 * @return array<string, mixed>|null Null when the action's scope claim does not resolve.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function bodyOf(array $match, array $subject, array $answers): ?array {
		$action = $match['action'];
		$stamp  = (new ActionScopeResolver(reader: $this->reader))->createStamp(action: $action, subject: $subject, appId: $match['app']);
		if ($stamp === null) {
			return null;
		}

		$kept = [];
		foreach ($this->answerFields(action: $action) as $field) {
			if (array_key_exists($field, $answers) === true && $answers[$field] !== null) {
				$kept[$field] = $answers[$field];
			}
		}

		return $this->writer->stamp(
			schema: (string)($action['schema'] ?? ''),
			scopeField: (string)($action['scopeField'] ?? 'subjectRef'),
			subjectRef: $stamp,
			organisation: (string)($subject['organisation'] ?? ''),
			data: (new FieldMap())->apply(action: $action, body: (new CreateBody())->build(action: $action, whitelisted: $kept))
		);
	}//end bodyOf()

	/**
	 * The subject's live draft of the action, or null. An expired one is discarded and reads as none.
	 *
	 * @param array<string, mixed>   $action     The create action.
	 * @param string                 $subjectRef The signed-in subject.
	 * @param DateTimeImmutable|null $now        The moment, for tests.
	 *
	 * @return array<string, mixed>|null `{id, status, data, updatedAt}`.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function find(array $action, string $subjectRef, ?DateTimeImmutable $now = null): ?array {
		$destination = $this->destinationOf(action: $action);
		$draft       = $this->drafts->findDraft(destination: $destination, subjectRef: $subjectRef, match: $this->matchOf(action: $action));
		if ($draft === null) {
			return null;
		}

		$expiresAt = $this->expiresAt(action: $action, updatedAt: (string)($draft['updatedAt'] ?? ''));
		if ($expiresAt === null || $expiresAt <= ($now ?? new DateTimeImmutable())) {
			// Retention on read (decision 201): past it, the draft is gone for the resident.
			$this->drafts->discardDraft(destination: $destination, subjectRef: $subjectRef, draftId: (string)$draft['id']);
			return null;
		}

		$draft['expiresAt'] = $expiresAt->format(DATE_ATOM);
		return $draft;
	}//end find()

	/**
	 * The answers of a draft as the form shows them: only the action's own fields.
	 *
	 * @param array<string, mixed> $action The create action.
	 * @param array<string, mixed> $draft  The draft.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function answersOf(array $action, array $draft): array {
		$data    = (new FieldMap())->reverse(action: $action, data: (array)($draft['data'] ?? []));
		$answers = [];
		foreach ($this->answerFields(action: $action) as $field) {
			if (array_key_exists($field, $data) === true) {
				$answers[$field] = $data[$field];
			}
		}

		return $answers;
	}//end answersOf()

	/**
	 * The action's retention in days: its `draft.retentionDays` within 1 to 90, else 30.
	 *
	 * @param array<string, mixed> $action The create action.
	 *
	 * @return int Days.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function retentionDays(array $action): int {
		$days = ($action['draft']['retentionDays'] ?? null);
		if (is_int($days) === false || $days < 1) {
			return self::DEFAULT_DAYS;
		}

		return min(self::MAX_DAYS, max(self::MIN_DAYS, $days));
	}//end retentionDays()

	/**
	 * When a draft last changed at `$updatedAt` expires, or null when the moment is unreadable.
	 *
	 * @param array<string, mixed> $action    The create action.
	 * @param string               $updatedAt The draft's last change, ISO 8601.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function expiresAt(array $action, string $updatedAt): ?DateTimeImmutable {
		if ($updatedAt === '') {
			return null;
		}

		try {
			$expiry = (new DateTimeImmutable($updatedAt))->modify('+' . $this->retentionDays(action: $action) . ' days');
		} catch (Throwable) {
			return null;
		}

		if ($expiry === false) {
			return null;
		}

		return $expiry;
	}//end expiresAt()

	/**
	 * The values a draft of this action must hold to be this action's: its scalar defaults.
	 *
	 * Two create actions may write one schema; their defaults (a case type)
	 * tell their drafts apart.
	 *
	 * @param array<string, mixed> $action The create action.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function matchOf(array $action): array {
		$match = [];
		foreach ((array)($action['defaults'] ?? []) as $key => $value) {
			if (is_string($key) === true && $key !== '' && is_scalar($value) === true) {
				$match[$key] = $value;
			}
		}

		return $match;
	}//end matchOf()

	/**
	 * Whether the action can hold a draft for this subject.
	 *
	 * @param array<string, mixed> $action  The action.
	 * @param array<string, mixed> $subject The subject.
	 *
	 * @return bool
	 */
	public function isDraftable(array $action, array $subject): bool {
		$destination = $this->destinationOf(action: $action);

		return ($action['type'] ?? '') === 'create'
			&& $destination['register'] !== ''
			&& $destination['schema'] !== ''
			&& PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($action['minTrust'] ?? null)) === true;
	}//end isDraftable()

	/**
	 * The action's answer fields: declared, not `claims`, not a file.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<int, string>
	 */
	private function answerFields(array $action): array {
		$files = [];
		foreach ((array)($action['fieldConfigs'] ?? []) as $field => $config) {
			if (is_array($config) === true && ($config['type'] ?? null) === FileFieldConfigNormaliser::TYPE_FILE) {
				$files[] = (string)$field;
			}
		}

		$fields = [];
		foreach ((array)($action['fields'] ?? []) as $field) {
			if (is_string($field) === true && $field !== '' && $field !== 'claims' && in_array($field, $files, true) === false) {
				$fields[] = $field;
			}
		}

		return $fields;
	}//end answerFields()
}//end class
