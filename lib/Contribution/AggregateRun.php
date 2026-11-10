<?php

/**
 * Portaliq Aggregate Run
 *
 * The state of one subject's aggregate while `PortalContributionRegistry`
 * collects it: the subject, its audience and trust, the contributions and
 * the step-up hints gathered so far, and the app and provider being read.
 * It never leaves the registry that builds it.
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
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * One aggregate in progress.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-multi-audience-provider-discovery
 */
final class AggregateRun {

	/**
	 * The subject's audience.
	 *
	 * @var string
	 */
	public readonly string $audience;

	/**
	 * The contributions collected so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $contributions = [];

	/**
	 * The step-up hints for entries dropped for trust.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $stepUp = [];

	/**
	 * The app being read.
	 *
	 * @var string
	 */
	public string $appId = '';

	/**
	 * The app's contribution provider.
	 *
	 * @var object|null
	 */
	public ?object $provider = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string               $trust   The subject's normalised trust.
	 */
	public function __construct(
		public readonly array $subject,
		public readonly string $trust,
	) {
		$this->audience = (string)($subject['audience'] ?? '');
	}//end __construct()
}//end class
