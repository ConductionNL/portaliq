<?php

/**
 * Portaliq Endpoint Draft Schema (endpoint-forward-draft-schema)
 *
 * An endpoint-forward action has no register or schema of its own: the
 * contributing app's endpoint receives the body and writes what it decides.
 * Such an action that keeps a `draft` MAY declare `draftSchema`
 * (`{register, schema}`): the OpenRegister schema where portaliq keeps the
 * resident's unfinished answers, through OpenRegister's own drafts, exactly
 * as it keeps a create action's draft on the action's schema. The endpoint
 * still receives only the final body; once it accepts it, portaliq discards
 * the draft.
 *
 * `draftSchema` is kept only on an action that is not a create, names an
 * endpoint and keeps a `draft`, and only as two slugs.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/endpoint-forward-draft-schema/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Reads and keeps an endpoint action's `draftSchema`.
 *
 * @spec openspec/changes/endpoint-forward-draft-schema/tasks.md#T1
 */
class EndpointDraftSchema {
	/**
	 * A register or schema slug.
	 */
	private const SLUG = '/^[a-z0-9][a-z0-9_-]{0,63}$/i';

	/**
	 * The action with `draftSchema` kept as `{register, schema}` when it may
	 * carry one, and without it otherwise.
	 *
	 * @param array<string, mixed> $action The action, its `draft` already normalised.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/endpoint-forward-draft-schema/tasks.md#T1
	 */
	public function normalise(array $action): array {
		if (array_key_exists('draftSchema', $action) === false) {
			return $action;
		}

		$destination = $this->declared(action: $action);
		unset($action['draftSchema']);
		if ($destination !== null && is_array($action['draft'] ?? null) === true) {
			$action['draftSchema'] = $destination;
		}

		return $action;
	}//end normalise()

	/**
	 * Where an endpoint action keeps its draft, or null when it declares no
	 * usable `draftSchema` (or is a create action, which keeps its draft on
	 * its own schema).
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array{register: string, schema: string}|null
	 *
	 * @spec openspec/changes/endpoint-forward-draft-schema/tasks.md#T2
	 */
	public function destinationOf(array $action): ?array {
		return $this->declared(action: $action);
	}//end destinationOf()

	/**
	 * Whether the action forwards to an endpoint and may keep a draft
	 * elsewhere: not a create, with an endpoint and a usable `draftSchema`.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/endpoint-forward-draft-schema/tasks.md#T2
	 */
	public function keepsDraftElsewhere(array $action): bool {
		return $this->declared(action: $action) !== null;
	}//end keepsDraftElsewhere()

	/**
	 * The field a draft of an endpoint action is stamped with: the action's
	 * `subjectField` (what the endpoint receives the subject in), else its
	 * `scopeField`, else `subjectRef`.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/endpoint-forward-draft-schema/tasks.md#T2
	 */
	public function scopeFieldOf(array $action): string {
		foreach (['subjectField', 'scopeField'] as $key) {
			$field = ($action[$key] ?? null);
			if (is_string($field) === true && $field !== '') {
				return $field;
			}
		}

		return 'subjectRef';
	}//end scopeFieldOf()

	/**
	 * The declared destination when the action may carry one.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function declared(array $action): ?array {
		$declared = ($action['draftSchema'] ?? null);
		$endpoint = ($action['endpoint'] ?? null);
		if (($action['type'] ?? '') === 'create' || is_string($endpoint) === false || $endpoint === ''
			|| is_array($declared) === false
		) {
			return null;
		}

		$register = ($declared['register'] ?? null);
		$schema   = ($declared['schema'] ?? null);
		if ($this->isSlug(value: $register) === false || $this->isSlug(value: $schema) === false) {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end declared()

	/**
	 * Whether a value is a register or schema slug.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 *
	 * @psalm-assert-if-true string $value
	 */
	private function isSlug(mixed $value): bool {
		return is_string($value) === true && preg_match(self::SLUG, $value) === 1;
	}//end isSlug()
}//end class
