<?php

/**
 * Create Action Matcher
 *
 * Which of the subject's `type: create` actions a create writes through. Two
 * create actions may write one schema (a request form and a complaint form
 * both writing pipelinq's `ticket`), so the first declared one is never taken
 * for granted: the client names the action by its id.
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
 * @spec openspec/changes/create-names-its-action/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Matches a create to one of the subject's create actions.
 *
 * @spec openspec/changes/create-names-its-action/tasks.md#T1
 */
class CreateActionMatcher {

	/**
	 * The answer when several actions write the target and none was named.
	 *
	 * @var string
	 */
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * The matched action, AMBIGUOUS, or null.
	 *
	 * A named id must be one of the subject's create actions on this register
	 * and schema, else null (refused). Without an id, a single candidate is
	 * used, and two or more answer AMBIGUOUS rather than a guess.
	 *
	 * @param array<string, mixed> $aggregate The subject's aggregated contributions.
	 * @param string               $register  The requested register.
	 * @param string               $schema    The requested schema.
	 * @param string               $actionId  The id the client named ('' for none).
	 *
	 * @return array{action: array<string, mixed>, app: string}|string|null
	 *
	 * @spec openspec/changes/create-names-its-action/tasks.md#T1
	 */
	public function match(array $aggregate, string $register, string $schema, string $actionId): array|string|null {
		$candidates = $this->candidates(aggregate: $aggregate, register: $register, schema: $schema);
		if ($actionId !== '') {
			foreach ($candidates as $candidate) {
				if (($candidate['action']['id'] ?? null) === $actionId) {
					return $candidate;
				}
			}

			return null;
		}

		if (count($candidates) > 1) {
			return self::AMBIGUOUS;
		}

		return ($candidates[0] ?? null);
	}//end match()

	/**
	 * Every create action for (register, schema), each with its app.
	 *
	 * @param array<string, mixed> $aggregate The subject's aggregated contributions.
	 * @param string               $register  The requested register.
	 * @param string               $schema    The requested schema.
	 *
	 * @return array<int, array{action: array<string, mixed>, app: string}>
	 */
	private function candidates(array $aggregate, string $register, string $schema): array {
		$candidates = [];
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach (($contribution['actions'] ?? []) as $action) {
				if (($action['type'] ?? '') === 'create'
					&& ($action['register'] ?? '') === $register
					&& ($action['schema'] ?? '') === $schema
				) {
					$candidates[] = ['action' => $action, 'app' => (string)($contribution['app'] ?? '')];
				}
			}
		}

		return $candidates;
	}//end candidates()
}//end class
