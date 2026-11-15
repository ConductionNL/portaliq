<?php

/**
 * Contract of SiteHead
 *
 * The public surface of SiteHead that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of SiteHead.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SiteHeadInterface {

	/**
	 * The head for one route of one portal.
	 *
	 * @param array<string, mixed>|null $portal    The serving portal, or null.
	 * @param string                    $route     The route asked for, e.g. `/contact`.
	 * @param string                    $locale    The document language.
	 * @param string                    $canonical The page's absolute address.
	 *
	 * @return array{title: string, description: string, robots: string, canonical: string, ogImage: string}
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	public function for(?array $portal, string $route, string $locale, string $canonical): array;
}//end interface
