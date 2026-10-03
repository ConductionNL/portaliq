<?php

/**
 * Portaliq Timeline Provider Method
 *
 * Which method names a collection's `timeline.provider` may name. A
 * contributing app declares its case history by naming a method on its own
 * provider (dossiq: `caseTimeline`), and portaliq calls that method with an
 * object id. The name comes from a manifest, so it is held to a plain
 * identifier and never to a method the contract already gives a meaning:
 * calling `getContribution` with a case id would be a confusion, and a magic
 * method would be worse.
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
 * @spec openspec/specs/portal-contribution-contract/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use ReflectionMethod;

/**
 * Decides whether a declared timeline provider name may be called.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md
 */
class TimelineProviderMethod {
	/**
	 * The contract's own methods, which a timeline may never name.
	 */
	private const RESERVED = ['getContribution', 'getAudience', 'getAudiences'];

	/**
	 * Keep a well-formed `timeline` (`{label, provider}`) on a collection;
	 * drop the key otherwise.
	 *
	 * The provider names a method on the contributing app's own provider that
	 * returns the object's history (portaliq#723). A name that is not a plain
	 * identifier, or names one of the contract's own methods, drops the whole
	 * key: fail closed, so a malformed manifest never makes portaliq call a
	 * method nobody meant. A label that is not text becomes empty.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md
	 */
	public function normaliseTimeline(array $collection): array {
		if (array_key_exists('timeline', $collection) === false) {
			return $collection;
		}

		$timeline = $collection['timeline'];
		if (is_array($timeline) === false || $this->accepts(name: ($timeline['provider'] ?? null)) === false) {
			unset($collection['timeline']);
			return $collection;
		}

		$label = ($timeline['label'] ?? '');
		if (is_string($label) === false) {
			$label = '';
		}

		$collection['timeline'] = ['label' => $label, 'provider' => $timeline['provider']];
		return $collection;
	}//end normaliseTimeline()

	/**
	 * Whether a declared name is one portaliq may call.
	 *
	 * @param mixed $name The declared `timeline.provider`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md
	 */
	public function accepts(mixed $name): bool {
		if (is_string($name) === false || preg_match('/^[a-z][A-Za-z0-9]*$/', $name) !== 1) {
			return false;
		}

		return in_array($name, self::RESERVED, true) === false;
	}//end accepts()

	/**
	 * Whether a provider has a public instance method of that name that
	 * portaliq may call with one id and nothing else. The message box
	 * recipient method (inbox-berichtenbox-channel) is held to the same rule
	 * as a timeline method.
	 *
	 * @param object $provider The contributing app's provider.
	 * @param string $method   The declared name.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md
	 */
	public function callableOn(object $provider, string $method): bool {
		if ($this->accepts(name: $method) === false || method_exists($provider, $method) === false) {
			return false;
		}

		$reflection = new ReflectionMethod($provider, $method);

		return $reflection->isPublic() === true
			&& $reflection->isStatic() === false
			&& $reflection->getNumberOfRequiredParameters() <= 1;
	}//end callableOn()
}//end class
