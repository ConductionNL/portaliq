<?php

/**
 * Portaliq Form Placement Run
 *
 * One page's pass of attaching form help: the serving portal and its forms,
 * read on first use.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
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
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * The serving portal of one pass, and its forms by identifier once read.
 *
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001
 */
final class FormPlacementRun {
	/**
	 * The portal's forms by identifier; null until the first form placement asks.
	 *
	 * @var array<string, array>|null
	 */
	public ?array $forms = null;

	/**
	 * Constructor.
	 *
	 * @param string $portal The serving portal's slug.
	 */
	public function __construct(
		public readonly string $portal,
	) {
	}//end __construct()
}//end class
