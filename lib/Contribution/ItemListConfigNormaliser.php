<?php
/**
 * Portaliq Item List Config Normaliser (my-dossiers)
 *
 * Sanitises the `itemList` a collection declares: the provider method that
 * lists one object's items (a dossier's publications), an optional label, and
 * an optional endpoint row action that removes one item. Presentation-only:
 * it never adds an action, and a removeAction must already be one of the
 * collection's own row actions with the field `itemId`.
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
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates `itemList`, fail closed.
 *
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
 */
class ItemListConfigNormaliser {
	/**
	 * The manifest key on the collection.
	 */
	public const KEY = 'itemList';

	/**
	 * Resolve every collection's `itemList` against the contribution's actions.
	 *
	 * @param array<int, array<string, mixed>> $collections The normalised collections.
	 * @param array<int, array<string, mixed>> $actions     The normalised actions.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-an-item-list-read-from-its-app-req-myd-001
	 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-a-resident-must-be-able-to-remove-one-item-req-myd-003
	 */
	public function resolve(array $collections, array $actions): array {
		foreach ($collections as $index => $collection) {
			if (is_array($collection) === false || array_key_exists(self::KEY, $collection) === false) {
				continue;
			}

			$declared = $collection[self::KEY];
			unset($collection[self::KEY]);
			if (is_array($declared) === true && (new TimelineProviderMethod())->accepts(name: ($declared['provider'] ?? null)) === true) {
				$label = '';
				if (is_string($declared['label'] ?? null) === true) {
					$label = $declared['label'];
				}

				$list = ['label' => $label, 'provider' => $declared['provider']];
				if ($this->removes(collection: $collection, actions: $actions, id: ($declared['removeAction'] ?? null)) === true) {
					$list['removeAction'] = $declared['removeAction'];
				}

				$collection[self::KEY] = $list;
			}

			$collections[$index] = $collection;
		}//end foreach

		return $collections;
	}//end resolve()

	/**
	 * Whether an id names a row action of this collection that takes `itemId`.
	 *
	 * @param array<string, mixed>             $collection The collection.
	 * @param array<int, array<string, mixed>> $actions    The actions.
	 * @param mixed                            $id         The declared id.
	 *
	 * @return bool
	 */
	private function removes(array $collection, array $actions, mixed $id): bool {
		if (is_string($id) === false || in_array($id, (array)($collection['rowActions'] ?? []), true) === false) {
			return false;
		}

		$rows = new RowActionResolver();
		foreach ($actions as $action) {
			if (is_array($action) === true && ($action['id'] ?? null) === $id) {
				return $rows->isEndpointRowAction(action: $action) === true && in_array('itemId', (array)($action['fields'] ?? []), true) === true;
			}
		}

		return false;
	}//end removes()
}//end class
