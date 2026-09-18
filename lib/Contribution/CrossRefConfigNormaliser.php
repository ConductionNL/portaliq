<?php

/**
 * Portaliq Cross Reference Config Normaliser
 *
 * Sanitises the `crossRefs` declaration a domain app puts on a create or
 * update action. The declaration names, per whitelisted field, which
 * collection the value in that field has to come out of: a citizen filing a
 * bezwaar names the case it objects to, and that case must be one of theirs.
 *
 * Without it, a create body is a flat map of whitelisted fields and a uuid in
 * one of them is accepted as typed. That is the write-IDOR the three deferred
 * portal creates were waiting on: the bezwaar's `tegenZaakId`, the message
 * reply's thread and the inspector's run submit all name an object the server
 * never checked the sender may see.
 *
 * 🔴 FAIL CLOSED MEANS DROPPING THE ACTION, NOT THE DECLARATION. Every other
 * normaliser here drops a malformed block and keeps the entry, because a
 * dropped block closes a surface. This one is the opposite: the block IS the
 * guard, so dropping it alone would leave the create standing with nothing
 * checking its references. An action whose guard cannot be read is therefore
 * removed from the manifest, and a create nobody can see is a create nobody
 * can call.
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
 * @spec openspec/changes/portal-create-cross-refs/specs/portal-contribution-contract/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates the per-action `crossRefs` declaration, fail closed.
 *
 * @spec openspec/changes/portal-create-cross-refs/specs/portal-contribution-contract/spec.md
 */
class CrossRefConfigNormaliser {
	/**
	 * The manifest key carrying the declaration.
	 */
	public const KEY = 'crossRefs';

	/**
	 * The action types a cross reference can be declared on. A read has no
	 * body to reference anything from.
	 */
	private const WRITING_TYPES = ['create', 'update'];

	/**
	 * Keys every declared reference must supply. Each one is a question the
	 * portal cannot answer for the domain app: which register and schema the
	 * referenced object lives in, and which field on it says whose it is.
	 */
	private const REQUIRED = ['register', 'schema', 'scopeField'];

	/**
	 * Sanitise the declaration on one action.
	 *
	 * @param array<string, mixed> $action    The action as declared.
	 * @param array<int, string>   $whitelist The fields the action accepts.
	 *
	 * @return array<string, mixed>|null The action, or null when it must be dropped.
	 *
	 * @spec openspec/changes/portal-create-cross-refs/specs/portal-contribution-contract/spec.md
	 */
	public function normaliseAction(array $action, array $whitelist): ?array {
		if (array_key_exists(self::KEY, $action) === false) {
			return $action;
		}

		if (in_array((string)($action['type'] ?? ''), self::WRITING_TYPES, true) === false) {
			// A declaration on a read is a mis-declaration, not a guard that
			// failed: there is no body to check. Drop the key, keep the action.
			unset($action[self::KEY]);
			return $action;
		}

		$declared = $action[self::KEY];
		if (is_array($declared) === false || $declared === []) {
			return null;
		}

		$config = [];
		foreach ($declared as $field => $reference) {
			$entry = $this->normaliseReference(field: $field, reference: $reference, whitelist: $whitelist);
			if ($entry === null) {
				return null;
			}

			$config[(string)$field] = $entry;
		}

		$action[self::KEY] = $config;

		// An anonymous caller owns nothing, so there is no scope to check a
		// reference against. The two keys are mutually exclusive, and the
		// guard is what survives: the action stays, without its anonymous
		// flag, which makes it reachable only with a subject.
		unset($action['anonymous']);

		return $action;
	}//end normaliseAction()

	/**
	 * One declared reference, or null when it is unusable.
	 *
	 * @param mixed                $field     The field name as authored.
	 * @param mixed                $reference The reference config as authored.
	 * @param array<int, string>   $whitelist The fields the action accepts.
	 *
	 * @return array<string, mixed>|null The sanitised reference, or null.
	 *
	 * @spec openspec/changes/portal-create-cross-refs/specs/portal-contribution-contract/spec.md
	 */
	private function normaliseReference(mixed $field, mixed $reference, array $whitelist): ?array {
		if (is_string($field) === false || $field === '' || is_array($reference) === false) {
			return null;
		}

		// A guard on a field the action never accepts can never fire, and a
		// guard that can never fire reads as protection that is not there.
		if (in_array($field, $whitelist, true) === false) {
			return null;
		}

		$entry = [];
		foreach (self::REQUIRED as $key) {
			$value = ($reference[$key] ?? null);
			if (is_string($value) === false || $value === '') {
				return null;
			}

			$entry[$key] = $value;
		}

		$entry['required'] = (($reference['required'] ?? false) === true);

		$scopeClaim = ($reference['scopeClaim'] ?? null);
		$entry['scopeClaim'] = '';
		if (is_string($scopeClaim) === true) {
			$entry['scopeClaim'] = $scopeClaim;
		}

		return $entry;
	}//end normaliseReference()
}//end class
