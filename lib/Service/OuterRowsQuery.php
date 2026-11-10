<?php

/**
 * Portaliq Outer Rows Query
 *
 * The outer query of a via read: which rows to ask OpenRegister for, how
 * they are matched to the verified targets, and how many.
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
 * @spec openspec/changes/via-read-scoped-query/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The parts of one PortalFilteredRows::outerRows() read.
 *
 * @spec openspec/changes/via-read-scoped-query/tasks.md#T1
 */
final class OuterRowsQuery {
	/**
	 * Constructor.
	 *
	 * @param string               $scopeField The collection's own scope field.
	 * @param array<string, true>  $targets    The verified target set.
	 * @param string               $match      'id' (forward) or 'scopeField' (reverse).
	 * @param int                  $limit      Maximum rows to return.
	 * @param array<string, mixed> $filter     The declared narrowing filter.
	 */
	public function __construct(
		public readonly string $scopeField,
		public readonly array $targets,
		public readonly string $match,
		public readonly int $limit,
		public readonly array $filter,
	) {
	}//end __construct()
}//end class
