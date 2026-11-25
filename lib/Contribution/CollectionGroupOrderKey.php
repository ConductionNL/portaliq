<?php

/**
 * Portaliq Collection Group Order Key (collection-group-order)
 *
 * A collection that groups its rows (`groupByField`) may carry `groupOrder`:
 * 'rows' puts each group where its first row stands in the sorted rows
 * (learniq's employer certificates: sorted on expiry, grouped per course, the
 * course that expires first on top); 'heading', the default, orders the
 * groups by their heading.
 *
 * The key is kept only as 'rows' or 'heading', and only beside a kept
 * `groupByField`.
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
 * @spec openspec/changes/collection-group-order/tasks.md#T2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a collection's `groupOrder` when it is 'rows' or 'heading' beside a
 * group field.
 *
 * @spec openspec/changes/collection-group-order/tasks.md#T2
 */
class CollectionGroupOrderKey {
	/**
	 * The group orders a collection may declare.
	 *
	 * @var list<string>
	 */
	public const ORDERS = ['rows', 'heading'];

	/**
	 * Keep `groupOrder` when it is a known order beside `groupByField`; drop
	 * it otherwise.
	 *
	 * Run it after `groupByField` is normalised, so a group order whose group
	 * field was dropped goes with it.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/collection-group-order/tasks.md#T2
	 */
	public function normalise(array $collection): array {
		if (array_key_exists('groupOrder', $collection) === false) {
			return $collection;
		}

		if (array_key_exists('groupByField', $collection) === false
			|| in_array($collection['groupOrder'], self::ORDERS, true) === false
		) {
			unset($collection['groupOrder']);
		}

		return $collection;
	}//end normalise()
}//end class
