<?php

/**
 * Portaliq Anonymous Read
 *
 * One read of a provider's anonymous contribution for one audience.
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
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * The state of one AnonymousContributions::forAudience() call. Never leaves
 * that class.
 *
 * @internal
 *
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
 */
final class AnonymousRead {
	/**
	 * Constructor.
	 *
	 * @param object             $provider The resolved provider.
	 * @param string             $appId    The app id (for logging and the `app` tag).
	 * @param string             $audience The audience to consult the provider for.
	 * @param array<int, string> $served   Every audience the provider serves.
	 */
	public function __construct(
		public readonly object $provider,
		public readonly string $appId,
		public readonly string $audience,
		public readonly array $served,
	) {
	}//end __construct()
}//end class
