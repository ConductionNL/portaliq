<?php

/**
 * Contract of PageChoice
 *
 * The public surface of PageChoice that other components call, so they depend
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
 * Contract of PageChoice.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PageChoiceInterface {

	/**
	 * Hide the pages a portal's navigation marks hidden and order the rest as
	 * listed. Pages the list does not name follow the named ones in their own
	 * order. Nothing but the page lists changes: a hidden page stays readable
	 * through the collection routes, because another portal of the same
	 * organisation may show it.
	 *
	 * @param array<string, mixed> $aggregate  The aggregate `{audience, organisation, contributions[]}`.
	 * @param mixed                $navigation The portal's navigation for this audience: `[{page, hidden}]`.
	 *
	 * @return array<string, mixed> The aggregate.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-portal-shows-the-pages-its-administrator-chose-in-the-chosen-order-req-pgc-001
	 */
	public function arrange(array $aggregate, mixed $navigation): array;
}//end interface
