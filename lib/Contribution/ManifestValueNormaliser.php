<?php

/**
 * Portaliq Manifest Value Normaliser (contribution-manifest-v3)
 *
 * The value-level primitives every manifest normaliser shares: enum coercion,
 * the string-keyed scalar-map shape check, and the fail-closed `anonymous`
 * flag rule that applies identically to a collection and to an action entry.
 *
 * INVARIANT: presentation-only and total — every method returns a safe value
 * and none of them ever throws.
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
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-and-elevated-trust-must-not-combine-on-one-entry
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Shared value-level primitives for the v3 manifest normalisers.
 *
 * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
 */
class ManifestValueNormaliser
{
    /**
     * Coerce `anonymous` to a strict boolean and enforce the fail-closed
     * mutual exclusion with a non-`low` `minTrust` on the SAME entry
     * (portal-page-provisioning): there is no subject to hold a trust level
     * on an anonymous call, so a manifest declaring BOTH is contradictory.
     * `anonymous` is dropped — never the `minTrust` — so the entry falls back
     * to requiring an authenticated, trust-checked bearer, never the reverse
     * (ADR-005). An absent or `low` `minTrust` does not conflict; only
     * `substantial`/`high` (or a malformed-but-truthy value that survived as
     * a string) trips the exclusion.
     *
     * @param array<string, mixed> $entry One collection or action entry.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-and-elevated-trust-must-not-combine-on-one-entry
     */
    public function normaliseAnonymousFlag(array $entry): array
    {
        if (array_key_exists('anonymous', $entry) === false) {
            return $entry;
        }

        $entry['anonymous'] = ($entry['anonymous'] === true || $entry['anonymous'] === 'true');
        if ($entry['anonymous'] === false) {
            return $entry;
        }

        $minTrust = ($entry['minTrust'] ?? null);
        if (is_string($minTrust) === true && $minTrust !== '' && $minTrust !== 'low') {
            // Fail-closed: drop `anonymous`, keep `minTrust` — the entry
            // reverts to requiring an authenticated, trust-checked bearer.
            unset($entry['anonymous']);
        }

        return $entry;
    }//end normaliseAnonymousFlag()

    /**
     * Return `value` when it is one of `allowed`, else `default`.
     *
     * @param mixed              $value   The candidate.
     * @param array<int, string> $allowed The allowed set.
     * @param string             $default The fallback.
     *
     * @return string
     *
     * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
     */
    public function oneOf(mixed $value, array $allowed, string $default): string
    {
        if (is_string($value) === true && in_array($value, $allowed, true) === true) {
            return $value;
        }

        return $default;
    }//end oneOf()

    /**
     * Whether a value is a map of string keys to scalar values.
     *
     * @param mixed $value The candidate.
     *
     * @return bool
     *
     * @spec openspec/changes/contribution-manifest-v3/tasks.md#T1
     */
    public function isStringKeyedScalarMap(mixed $value): bool
    {
        if (is_array($value) === false || $value === []) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) === false || (is_scalar($item) === false && $item !== null)) {
                return false;
            }
        }

        return true;
    }//end isStringKeyedScalarMap()
}//end class
