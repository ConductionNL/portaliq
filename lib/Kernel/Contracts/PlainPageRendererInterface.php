<?php

/**
 * Contract of PlainPageRenderer
 *
 * The public surface of PlainPageRenderer that other components call, so they depend
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

use OCA\Portaliq\Service\Cms\PlainAddress;

/**
 * Contract of PlainPageRenderer.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PlainPageRendererInterface {

	/**
	 * The plain page of one route.
	 *
	 * @param array<string, mixed>|null $portal  The serving portal, or null.
	 * @param string                    $route   The route asked for.
	 * @param string                    $locale  The document language.
	 * @param string                    $query   The request's `_search`.
	 * @param int                       $page    The request's `_page`.
	 * @param PlainAddress              $address The addresses of this request.
	 * @param string                    $record  The request's `record`, the id a public records block shows; else ''.
	 *
	 * @return array<string, mixed> `status` (200 or 404), `portalTitle`, `menu`, `title`, `summary`, `blocks`, `fullUrl`.
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function render(?array $portal, string $route, string $locale, string $query, int $page, PlainAddress $address, string $record = ''): array;
}//end interface
