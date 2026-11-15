<?php

/**
 * Contract of ContributionCensus
 *
 * The public surface of ContributionCensus that other components call, so they depend
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
 * Contract of ContributionCensus.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContributionCensusInterface {

	/**
	 * A row for an app whose provider was found and answered.
	 */
	public const STATUS_PROVIDER = 'provider';

	/**
	 * A row for an app known to integrate without a provider.
	 */
	public const STATUS_ENDPOINTS = 'endpoints';

	/**
	 * A row for an app whose provider threw.
	 */
	public const STATUS_ERROR = 'error';

	/**
	 * The census: one row per enabled app, in app id order.
	 *
	 * Each row is `{app, status, pattern, error, audiences}`; `audiences` is a
	 * list of `{audience, collections, pages, actions}` counted for a synthetic
	 * subject of that audience, so no real person's data is read.
	 *
	 * @return array<int, array<string, mixed>> The rows, in app id order.
	 *
	 * @spec openspec/changes/contributing-apps-census/specs/portal-contribution-contract/spec.md#requirement-an-administrator-can-see-which-apps-contribute-to-the-portal-req-cac-001
	 */
	public function rows(): array;
}//end interface
