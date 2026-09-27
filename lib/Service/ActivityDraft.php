<?php

/**
 * Activity Draft
 *
 * Validates and sanitises a new activity before it is stored
 * (extracurricular-activity-offer). The required fields must be sound or the
 * draft is refused; the optional ones are kept only when well formed, never
 * guessed. Pure: it reads the parameters it is handed and nothing else.
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
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Builds the stored shape of a new draft activity.
 *
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
 */
class ActivityDraft {
	/**
	 * The kinds an activity may have.
	 */
	public const KINDS = ['club', 'sport', 'culture', 'trip', 'course', 'other'];

	/**
	 * The draft to store, or null when a required field is unsound.
	 *
	 * Required: a title, a kind from KINDS, a target naming at least one
	 * school, group or child, a term start and a capacity of at least one.
	 * The status is always `draft` and the author is the signed-in staff
	 * member, whatever the parameters say.
	 *
	 * @param array{title: string, kind: string, target: array<string, mixed>, termStart: string, capacity: int} $required The required fields.
	 * @param array<string, mixed> $params The request parameters, for the optional fields.
	 * @param string $author The staff member creating it.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
	 */
	public function build(array $required, array $params, string $author): ?array {
		if ($required['title'] === '' || $required['termStart'] === '' || $required['capacity'] < 1
			|| in_array($required['kind'], self::KINDS, true) === false
			|| $this->hasAnyTarget(target: $required['target']) === false
		) {
			return null;
		}

		return array_merge(
			$this->optionalFields(params: $params),
			$required,
			['status' => 'draft', 'authorRef' => $author]
		);
	}//end build()

	/**
	 * The optional fields of a new activity, sanitised: malformed values are
	 * dropped, never guessed.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed>
	 */
	private function optionalFields(array $params): array {
		$fields = [];
		foreach (['termEnd', 'signupDeadline', 'description', 'location'] as $key) {
			$value = ($params[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				$fields[$key] = $value;
			}
		}

		foreach (['waitlistEnabled', 'paymentRequested'] as $key) {
			$value = ($params[$key] ?? null);
			$fields[$key] = ($value === true || $value === 'true' || $value === 1 || $value === '1');
		}

		$fields['childrenPerSupervisor'] = max(0, (int)($params['childrenPerSupervisor'] ?? 0));
		$fields['supervisorRefs'] = array_values(
			array_unique(array_filter((array)($params['supervisorRefs'] ?? []), static fn ($ref) => is_string($ref) === true && $ref !== ''))
		);
		$fields['sessions'] = $this->sessions(declared: ($params['sessions'] ?? []));

		return $fields;
	}//end optionalFields()

	/**
	 * Keep the well-formed sessions: a unique string id and a start.
	 *
	 * @param mixed $declared The declared sessions.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function sessions(mixed $declared): array {
		$kept = [];
		foreach ((array)$declared as $session) {
			if ($this->isWellFormedSession(session: $session) === false || isset($kept[$session['id']]) === true) {
				continue;
			}

			$entry = ['id' => $session['id'], 'start' => $session['start']];
			foreach (['end', 'location'] as $key) {
				if (is_string($session[$key] ?? null) === true && $session[$key] !== '') {
					$entry[$key] = $session[$key];
				}
			}

			$kept[$session['id']] = $entry;
		}

		return array_values($kept);
	}//end sessions()

	/**
	 * Whether a declared session has a string id and a string start.
	 *
	 * @param mixed $session The declared session.
	 *
	 * @return bool
	 *
	 * @psalm-assert-if-true array{id: non-empty-string, start: non-empty-string, end?: mixed, location?: mixed} $session
	 */
	private function isWellFormedSession(mixed $session): bool {
		return is_array($session) === true
			&& is_string($session['id'] ?? null) === true && $session['id'] !== ''
			&& is_string($session['start'] ?? null) === true && $session['start'] !== '';
	}//end isWellFormedSession()

	/**
	 * Whether a target names at least one school, group or child.
	 *
	 * @param array<string, mixed> $target The target.
	 *
	 * @return bool
	 */
	private function hasAnyTarget(array $target): bool {
		if (is_string($target['schoolRef'] ?? null) === true && $target['schoolRef'] !== '') {
			return true;
		}

		return (is_array($target['groupRefs'] ?? null) === true && count($target['groupRefs']) > 0)
			|| (is_array($target['childRefs'] ?? null) === true && count($target['childRefs']) > 0);
	}//end hasAnyTarget()
}//end class
