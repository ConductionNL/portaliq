<?php

/**
 * Portaliq Steps Provider Method (site-mijn-omgeving-components)
 *
 * A `cases` collection may say where a case stands: `steps: {label?,
 * provider}` names a method on the contributing app's provider that answers
 * the steps of one case, and `dueField` and `turnField` name the projected
 * fields that hold the date the organisation answers by and whose turn it is.
 *
 * SECURITY: the provider name passes the same rule as a timeline provider
 * (TimelineProviderMethod), so a manifest can never make portaliq call one of
 * the contract's own methods. A provider answer is held to a fixed shape and
 * an entry that does not fit is dropped, never passed on as it came.
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
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cases-collection-may-supply-steps-an-answer-date-and-whose-turn-it-is-req-smo-022
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a cases collection's progress keys, and the provider's steps, in shape.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cases-collection-may-supply-steps-an-answer-date-and-whose-turn-it-is-req-smo-022
 */
class StepsProviderMethod {
	/**
	 * The states a step may be in.
	 */
	private const STATES = ['done', 'current', 'todo'];

	/**
	 * Keep `steps`, `dueField` and `turnField` on a `cases` collection when
	 * they fit; drop each that does not, and all three on any other kind.
	 *
	 * @param array<string, mixed> $collection The collection, its `kind` already normalised.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cases-collection-may-supply-steps-an-answer-date-and-whose-turn-it-is-req-smo-022
	 */
	public function normalise(array $collection): array {
		$declared = [
			'steps'     => ($collection['steps'] ?? null),
			'dueField'  => ($collection['dueField'] ?? null),
			'turnField' => ($collection['turnField'] ?? null),
		];
		unset($collection['steps'], $collection['dueField'], $collection['turnField']);

		if (($collection['kind'] ?? null) !== 'cases') {
			return $collection;
		}

		$steps = $declared['steps'];
		if (is_array($steps) === true && (new TimelineProviderMethod())->accepts(name: ($steps['provider'] ?? null)) === true) {
			$label = ($steps['label'] ?? '');
			if (is_string($label) === false) {
				$label = '';
			}

			$collection['steps'] = ['label' => $label, 'provider' => $steps['provider']];
		}

		foreach (['dueField', 'turnField'] as $key) {
			if ($this->projects(collection: $collection, field: $declared[$key]) === true) {
				$collection[$key] = $declared[$key];
			}
		}

		return $collection;
	}//end normalise()

	/**
	 * The steps of a provider answer that fit `{label, description?, state,
	 * date?}`, in the order given. An entry without a label, or with a state
	 * other than done, current or todo, is dropped.
	 *
	 * @param array<int, mixed> $entries The provider's answer.
	 *
	 * @return array<int, array<string, string>>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cases-collection-may-supply-steps-an-answer-date-and-whose-turn-it-is-req-smo-022
	 */
	public function steps(array $entries): array {
		$out = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$label = ($entry['label'] ?? null);
			$state = ($entry['state'] ?? null);
			if (is_string($label) === false || trim($label) === '' || in_array($state, self::STATES, true) === false) {
				continue;
			}

			$step = ['label' => trim($label), 'state' => $state];
			foreach (['description', 'date'] as $optional) {
				if (is_string($entry[$optional] ?? null) === true && $entry[$optional] !== '') {
					$step[$optional] = $entry[$optional];
				}
			}

			$out[] = $step;
		}

		return $out;
	}//end steps()

	/**
	 * Whether a declared field name is one the collection projects (any
	 * field, when it projects none).
	 *
	 * @param array<string, mixed> $collection The collection.
	 * @param mixed                $field      The declared name.
	 *
	 * @return bool
	 */
	private function projects(array $collection, mixed $field): bool {
		if (is_string($field) === false || $field === '') {
			return false;
		}

		$fields = ($collection['fields'] ?? null);
		return is_array($fields) === false || in_array($field, $fields, true) === true;
	}//end projects()
}//end class
