<?php

/**
 * Portaliq Public Records Normaliser
 *
 * Keeps the well-formed `publicRecords` a contribution declares, and strips the
 * provider names from what leaves the server.
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
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/specs/portal-voting-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * An entry survives only with a plain id, a label and two provider names that
 * pass the timeline rule and exist as public methods on the provider. Anything
 * else is dropped, fail-closed.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t1
 */
class PublicRecordsNormaliser {

	/**
	 * The shape of a record list id.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[a-z][a-zA-Z0-9_]*$/';

	/**
	 * The entries a provider may be called for, with their provider names.
	 *
	 * @param mixed $entries The declared `publicRecords`.
	 * @param object|null $provider The contributing app's provider, for the method check.
	 *
	 * @return array<int, array<string, string>> `{id, label, group, listProvider, recordProvider}` per kept entry.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t1
	 */
	public function normalise(mixed $entries, ?object $provider): array {
		if (is_array($entries) === false || $provider === null) {
			return [];
		}

		$methods = new TimelineProviderMethod();
		$kept    = [];
		$seen    = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$id    = $entry['id'] ?? null;
			$label = $entry['label'] ?? null;
			$list  = $entry['listProvider'] ?? null;
			$one   = $entry['recordProvider'] ?? null;
			if (is_string($id) === false || preg_match(self::ID_PATTERN, $id) !== 1 || isset($seen[$id]) === true) {
				continue;
			}

			if (is_string($label) === false || trim($label) === '') {
				continue;
			}

			$listCallable = $methods->callableOn(provider: $provider, method: (string)$list);
			$oneCallable  = $methods->callableOn(provider: $provider, method: (string)$one);
			if ($listCallable === false || $oneCallable === false) {
				continue;
			}

			$group = $entry['group'] ?? '';
			if (is_string($group) === false) {
				$group = '';
			}

			$seen[$id] = true;
			$kept[]    = ['id' => $id, 'label' => trim($label), 'group' => $group, 'listProvider' => (string)$list, 'recordProvider' => (string)$one];
		}//end foreach

		return $kept;
	}//end normalise()

	/**
	 * What an aggregate carries: id, label, group and app, never a provider name.
	 *
	 * @param array<int, array<string, string>> $kept The normalised entries.
	 * @param string $app The contributing app.
	 *
	 * @return array<int, array<string, string>> The public view.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t1
	 */
	public function view(array $kept, string $app): array {
		return array_map(
			static fn (array $entry): array => ['id' => $entry['id'], 'label' => $entry['label'], 'group' => $entry['group'], 'app' => $app],
			$kept
		);
	}//end view()
}//end class
