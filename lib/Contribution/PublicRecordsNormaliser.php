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
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/specs/site-council-records/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Kernel\Contracts\PublicRecordsNormaliserInterface;

/**
 * An entry survives only with a plain id, a label and two provider names that
 * pass the timeline rule and exist as public methods on the provider. Anything
 * else is dropped, fail-closed.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t1
 */
class PublicRecordsNormaliser implements PublicRecordsNormaliserInterface {

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

		$run  = new PublicRecordsRun(provider: $provider, methods: new TimelineProviderMethod());
		$kept = [];
		foreach ($entries as $entry) {
			$clean = $this->entry(entry: $entry, run: $run);
			if ($clean === null) {
				continue;
			}

			$run->seen[$clean['id']] = true;
			$kept[] = $clean;
		}

		return $kept;
	}//end normalise()

	/**
	 * One declared entry, or null when it is malformed, a duplicate or names a provider method that is not callable.
	 *
	 * @param mixed            $entry The declared entry.
	 * @param PublicRecordsRun $run   The provider, the method check and the ids kept so far.
	 *
	 * @return array<string, string>|null The kept entry.
	 */
	private function entry(mixed $entry, PublicRecordsRun $run): ?array {
		if (is_array($entry) === false || $this->isNewId(id: ($entry['id'] ?? null), run: $run) === false
			|| $this->hasLabel(entry: $entry) === false || $this->namesCallableProviders(entry: $entry, run: $run) === false
		) {
			return null;
		}

		return [
			'id'             => $entry['id'],
			'label'          => trim($entry['label']),
			'group'          => $this->group(value: ($entry['group'] ?? '')),
			'listProvider'   => (string)$entry['listProvider'],
			'recordProvider' => (string)$entry['recordProvider'],
		];
	}//end entry()

	/**
	 * Whether an entry id is a plain id not kept before.
	 *
	 * @param mixed            $id  The declared id.
	 * @param PublicRecordsRun $run The ids kept so far.
	 *
	 * @return bool
	 */
	private function isNewId(mixed $id, PublicRecordsRun $run): bool {
		return is_string($id) === true && preg_match(self::ID_PATTERN, $id) === 1 && isset($run->seen[$id]) === false;
	}//end isNewId()

	/**
	 * Whether an entry has a label that says something.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return bool
	 */
	private function hasLabel(array $entry): bool {
		$label = $entry['label'] ?? null;
		return is_string($label) === true && trim($label) !== '';
	}//end hasLabel()

	/**
	 * Whether an entry's list and record providers are both methods the
	 * provider lets portaliq call.
	 *
	 * @param array<string, mixed> $entry The declared entry.
	 * @param PublicRecordsRun     $run   The provider and the method check.
	 *
	 * @return bool
	 */
	private function namesCallableProviders(array $entry, PublicRecordsRun $run): bool {
		$listCallable = $run->methods->callableOn(provider: $run->provider, method: (string)($entry['listProvider'] ?? null));
		$oneCallable  = $run->methods->callableOn(provider: $run->provider, method: (string)($entry['recordProvider'] ?? null));
		return $listCallable === true && $oneCallable === true;
	}//end namesCallableProviders()

	/**
	 * The group of an entry, empty when it is not text.
	 *
	 * @param mixed $value The declared group.
	 *
	 * @return string The group.
	 */
	private function group(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end group()

	/**
	 * Replace the declared `publicRecords` of a contribution with the validated view.
	 *
	 * @param array<string, mixed> $contribution One app's contribution.
	 * @param object $provider The app's provider.
	 * @param string $appId The app id.
	 *
	 * @return array<string, mixed> The contribution with the sanitised list.
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t1
	 */
	public function attach(array $contribution, object $provider, string $appId): array {
		$kept = $this->normalise(entries: ($contribution['publicRecords'] ?? null), provider: $provider);
		unset($contribution['publicRecords']);
		if ($kept !== []) {
			$contribution['publicRecords'] = $this->view(kept: $kept, app: $appId);
		}

		return $contribution;
	}//end attach()

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
