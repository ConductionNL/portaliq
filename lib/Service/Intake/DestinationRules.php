<?php

/**
 * Portaliq Destination Rules
 *
 * A form binding may name, besides its `destination`, rules that pick another
 * destination from the answers: `destinationRules: [{when, destination}]`. The
 * first rule whose `when` holds decides; with no match the binding's own
 * `destination` applies. `when` is the local `visibleWhen` grammar the form's
 * conditional questions use, so the screen and the server read it alike.
 *
 * The routing happens before the direct submit (decision 179): there is still
 * no step after the submit; the submit simply goes to the chosen register and
 * schema.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-each-form-names-where-its-submissions-go-req-fgv-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * Picks a binding's destination from the answers.
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-each-form-names-where-its-submissions-go-req-fgv-002
 */
class DestinationRules {
	/**
	 * The finding code for a rule that cannot route: no condition, a condition
	 * the portal cannot check, or a destination without register or schema.
	 */
	public const INVALID_RULE = 'destination-rule-invalid';

	/**
	 * Constructor.
	 *
	 * @param VisibleWhenLocal $conditions Reads a rule's `when` over the answers.
	 */
	public function __construct(
		private readonly VisibleWhenLocal $conditions = new VisibleWhenLocal(),
	) {
	}//end __construct()

	/**
	 * The binding with the destination its answers route to: the first valid
	 * rule whose `when` holds, else the binding's own `destination`.
	 *
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return array<string, mixed> The binding, its `destination` replaced when a rule matched.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-each-form-names-where-its-submissions-go-req-fgv-002
	 */
	public function routed(array $binding, array $answers): array {
		foreach (self::rulesOf(binding: $binding) as $rule) {
			if ($this->problemOf(rule: $rule) === null
				&& $this->conditions->isVisible(condition: $rule['when'], answers: $answers) === true
			) {
				$binding['destination'] = $rule['destination'];
				return $binding;
			}
		}

		return $binding;
	}//end routed()

	/**
	 * The binding once per rule, each with that rule's destination, so the
	 * publish check can hold every destination a submit may reach against its
	 * schema. A rule that cannot route is a finding instead.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return array{bindings: array<int, array<string, mixed>>, findings: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-each-form-names-where-its-submissions-go-req-fgv-002
	 */
	public function alternatives(array $binding): array {
		$bindings = [];
		$findings = [];
		foreach (self::rulesOf(binding: $binding) as $index => $rule) {
			$problem = $this->problemOf(rule: $rule);
			if ($problem !== null) {
				$findings[] = ['property' => 'destinationRules.' . $index, 'code' => self::INVALID_RULE, 'message' => $problem];
				continue;
			}

			$bindings[] = array_merge($binding, ['destination' => $rule['destination']]);
		}

		return ['bindings' => $bindings, 'findings' => $findings];
	}//end alternatives()

	/**
	 * Why a rule cannot route, or null when it can.
	 *
	 * @param mixed $rule The rule.
	 *
	 * @return string|null The reason.
	 */
	private function problemOf(mixed $rule): ?string {
		if (is_array($rule) === false || is_array($rule['when'] ?? null) === false || $rule['when'] === []) {
			return 'This destination rule has no condition.';
		}

		if ($this->conditions->isDecidable(condition: $rule['when']) === false) {
			return 'The portal cannot check the condition of this destination rule.';
		}

		$destination = ($rule['destination'] ?? null);
		if (is_array($destination) === false
			|| trim((string)($destination['register'] ?? '')) === ''
			|| trim((string)($destination['schema'] ?? '')) === ''
		) {
			return 'This destination rule does not say which register and schema the answers go to.';
		}

		return null;
	}//end problemOf()

	/**
	 * The binding's rules, in order; none when it names no list.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return array<int, mixed> The rules.
	 */
	private static function rulesOf(array $binding): array {
		$rules = ($binding['destinationRules'] ?? null);
		if (is_array($rules) === false) {
			return [];
		}

		return array_values($rules);
	}//end rulesOf()
}//end class
