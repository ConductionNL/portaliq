<?php

/**
 * Portaliq Example Site Proof
 *
 * Compares what a declaration holds with what the instance stores, key by
 * key, and names every declared key that did not arrive as declared.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleSite
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
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleSite;

/**
 * Names the declared keys a stored object lost.
 *
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
 */
class ExampleSiteProof {
	/**
	 * Every declared path the stored value does not hold as declared.
	 *
	 * An empty declared list or object names nothing that could be lost. A
	 * date is the same date in another time zone's notation.
	 *
	 * @param mixed  $declared What the declaration holds.
	 * @param mixed  $stored   What the instance holds at the same place.
	 * @param string $path     The path so far, dot-separated.
	 *
	 * @return array<int, string> The lost paths.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-install-must-prove-what-arrived
	 */
	public function lostPaths(mixed $declared, mixed $stored, string $path = ''): array {
		if (is_array($declared) === false) {
			if ($this->sameValue(declared: $declared, stored: $stored) === true) {
				return [];
			}

			return [$path];
		}

		$lost = [];
		foreach ($declared as $key => $value) {
			$here = ltrim($path . '.' . $key, '.');
			if (is_array($stored) === true && array_key_exists($key, $stored) === true) {
				$lost = array_merge($lost, $this->lostPaths(declared: $value, stored: $stored[$key], path: $here));
				continue;
			}

			if ($value !== []) {
				$lost[] = $here;
			}
		}

		return $lost;
	}//end lostPaths()

	/**
	 * Whether a stored scalar is the declared one.
	 *
	 * @param mixed $declared The declared value.
	 * @param mixed $stored   The stored value.
	 *
	 * @return bool
	 */
	private function sameValue(mixed $declared, mixed $stored): bool {
		if ($declared === $stored) {
			return true;
		}

		if (is_numeric($declared) === true && is_numeric($stored) === true) {
			return (float)$declared === (float)$stored;
		}

		if (is_string($declared) === false || is_string($stored) === false || preg_match('/^\d{4}-\d{2}-\d{2}T/', $declared) !== 1) {
			return false;
		}

		$left = strtotime($declared);

		return $left !== false && $left === strtotime($stored);
	}//end sameValue()
}//end class
