<?php

/**
 * Portaliq Provider Audiences
 *
 * Which audiences a contribution provider serves: `getAudiences()` when it
 * has one, else the single `getAudience()`, else none.
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
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Which audiences a contribution provider serves, read by duck typing.
 *
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T2
 */
final class ProviderAudiences {
	/**
	 * The full set of audiences a provider serves (contract v2, A2 duck
	 * typing — same preference order as `serves()`): `getAudiences()`
	 * when present, else the single `getAudience()` value, else none.
	 *
	 * @param object $provider The resolved provider.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T2
	 */
	public function served(object $provider): array {
		if (method_exists($provider, 'getAudiences') === true) {
			$audiences = $provider->getAudiences();
			if (is_array($audiences) === false) {
				return [];
			}

			return array_values(array_filter($audiences, static fn ($a) => is_string($a) === true && $a !== ''));
		}

		if (method_exists($provider, 'getAudience') === true) {
			$audience = $provider->getAudience();
			if (is_string($audience) === true && $audience !== '') {
				return [$audience];
			}
		}

		return [];
	}//end served()

	/**
	 * Whether a provider serves the subject's audience (contract v2, A2).
	 *
	 * Prefers the duck-typed `getAudiences(): array` when present (multi-
	 * audience providers); falls back to the v1 `getAudience(): string`. A
	 * provider exposing neither serves nobody (fail-closed). The audience
	 * vocabulary is an open string set — no enum is enforced here.
	 *
	 * @param object $provider The resolved provider.
	 * @param string $audience The subject's audience.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T2
	 */
	public function serves(object $provider, string $audience): bool {
		if (method_exists($provider, 'getAudiences') === true) {
			$audiences = $provider->getAudiences();
			if (is_array($audiences) === false) {
				return false;
			}

			return in_array($audience, $audiences, true);
		}

		if (method_exists($provider, 'getAudience') === true) {
			return $provider->getAudience() === $audience;
		}

		return false;
	}//end serves()
}//end class
