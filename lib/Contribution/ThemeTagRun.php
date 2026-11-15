<?php

/**
 * Portaliq Theme Tag Run
 *
 * The state of one ThemeChoice::arrange() call: the slugs the portal
 * declares and the themes a kept tag has used so far. It never leaves
 * ThemeChoice.
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
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * One arrange() call of the theme tags.
 *
 * @internal
 *
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t02
 */
final class ThemeTagRun {
	/**
	 * The declared themes a kept tag has named so far.
	 *
	 * @var array<string, bool>
	 */
	public array $used = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $slugs The slugs the portal declares.
	 */
	public function __construct(
		public readonly array $slugs,
	) {
	}//end __construct()
}//end class
