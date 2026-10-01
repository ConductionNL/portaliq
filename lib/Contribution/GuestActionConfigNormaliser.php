<?php

/**
 * Portaliq Guest Action Config Normaliser
 *
 * Validates the guest half of an endpoint action (identity-guest-page-for-
 * signed-links D1): an action marked `guest: true` is one a person without a
 * portal account may do from a link the contributing app signed. It survives
 * only with a `tokenField` to carry that token, instance-local endpoints, and
 * no trust above `low`; every other guest declaration is removed with its
 * action. Fail-closed, no I/O.
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
 * @spec openspec/changes/identity-guest-page-for-signed-links/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a well-formed guest action and removes every other one.
 *
 * @spec openspec/changes/identity-guest-page-for-signed-links/tasks.md#T01
 */
class GuestActionConfigNormaliser {
	/**
	 * Text keys the guest page shows; a non-string value is dropped.
	 */
	private const TEXT_KEYS = ['label', 'confirmText', 'successText'];

	/**
	 * Normalise one action's guest declaration.
	 *
	 * An action without `guest` passes unchanged. `guest` set to anything but
	 * `true` is removed, so only an explicit declaration opens a guest route.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>|null The action, or null when its guest declaration is unusable.
	 *
	 * @spec openspec/changes/identity-guest-page-for-signed-links/tasks.md#T01
	 */
	public function normaliseAction(array $action): ?array {
		if (array_key_exists('guest', $action) === false) {
			return $action;
		}

		if ($action['guest'] !== true) {
			unset($action['guest']);
			return $action;
		}

		$tokenField = ($action['tokenField'] ?? null);
		if (is_string($tokenField) === false || preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $tokenField) !== 1) {
			return null;
		}

		// A guest is never more than `low` (REQ-GST-001).
		if (array_key_exists('minTrust', $action) === true && $action['minTrust'] !== 'low') {
			return null;
		}

		if ($this->isLocalPath(path: ($action['endpoint'] ?? null)) === false) {
			return null;
		}

		if (array_key_exists('previewEndpoint', $action) === true
			&& $this->isLocalPath(path: $action['previewEndpoint']) === false
		) {
			return null;
		}

		foreach (self::TEXT_KEYS as $key) {
			if (array_key_exists($key, $action) === true && is_string($action[$key]) === false) {
				unset($action[$key]);
			}
		}

		return $action;
	}//end normaliseAction()

	/**
	 * Whether a value is an instance-local absolute path: a leading slash, no
	 * protocol-relative `//`, no scheme (the SSRF rule of every endpoint action).
	 *
	 * @param mixed $path The candidate path.
	 *
	 * @return bool
	 */
	private function isLocalPath(mixed $path): bool {
		return is_string($path) === true
			&& str_starts_with($path, '/') === true
			&& str_starts_with($path, '//') === false
			&& str_contains($path, '://') === false;
	}//end isLocalPath()
}//end class
