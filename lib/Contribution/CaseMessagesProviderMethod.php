<?php

/**
 * Portaliq Case Messages Provider Method
 *
 * Keeps a collection's `messages` declaration (`{label, provider}`): the
 * method on the contributing app's own provider that lists the messages about
 * one record, both ways (dossiq: `caseMessages`). The name comes from a
 * manifest, so it is held to the same rule as a timeline method: a plain
 * identifier that is not one of the contract's own methods. A malformed
 * declaration drops the key, so portaliq never calls a method nobody meant.
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
 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalise the `messages` declaration on one collection.
 *
 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
 */
class CaseMessagesProviderMethod {

	/**
	 * The collection key this normaliser owns.
	 */
	public const KEY = 'messages';

	/**
	 * Keep a well-formed `messages` (`{label, provider}`) on a collection;
	 * drop the key otherwise. A label that is not text becomes empty.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed> The collection, with a sound `messages` kept or the key dropped.
	 *
	 * @spec openspec/changes/capability-rows-said-and-planned/tasks.md#t07
	 */
	public function normalise(array $collection): array {
		if (array_key_exists(self::KEY, $collection) === false) {
			return $collection;
		}

		$declared = $collection[self::KEY];
		if (is_array($declared) === false || (new TimelineProviderMethod())->accepts(name: ($declared['provider'] ?? null)) === false) {
			unset($collection[self::KEY]);
			return $collection;
		}

		$label = ($declared['label'] ?? '');
		if (is_string($label) === false) {
			$label = '';
		}

		$collection[self::KEY] = ['label' => $label, 'provider' => $declared['provider']];
		return $collection;
	}//end normalise()
}//end class
