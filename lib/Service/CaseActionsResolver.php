<?php

/**
 * Portaliq Case Actions Resolver
 *
 * Lists the create actions a case app placed on the page of one of its cases
 * (`onCase`), and says for each whether it is open today. The window is
 * computed here and only here: the case screen never computes a date, and the
 * create route asks the same question again before it writes.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-case-page-offers-the-actions-the-server-lists-req-coc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Contribution\CrossRefConfigNormaliser;
use OCA\Portaliq\Contribution\OnCaseConfigNormaliser;

/**
 * Resolves the actions offered on a case page and their windows.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-case-page-offers-the-actions-the-server-lists-req-coc-002
 */
class CaseActionsResolver {
	/**
	 * The zone a window's days are counted in.
	 */
	private const ZONE = 'Europe/Amsterdam';

	/**
	 * Every create action in the aggregate placed on a case of this register
	 * and schema, in declared order, each with its window state.
	 *
	 * An entry carries `form`: the action as the resident's manifest already
	 * holds it, so the case screen can draw its fields.
	 *
	 * @param array<string, mixed>     $aggregate The subject's normalised contributions.
	 * @param string                   $register  The case's register.
	 * @param string                   $schema    The case's schema.
	 * @param array<string, mixed>     $case      The case row, read through the scoped reader.
	 * @param DateTimeImmutable|null   $now       The moment to judge the window at; now by default.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t03
	 */
	public function resolve(array $aggregate, string $register, string $schema, array $case, ?DateTimeImmutable $now=null): array {
		$entries = [];
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			foreach (($contribution['actions'] ?? []) as $action) {
				if (is_array($action) === false
					|| $this->placedOn(action: $action, register: $register, schema: $schema) === false
				) {
					continue;
				}

				$entries[] = $this->entry(
					app: (string)($contribution['app'] ?? ''),
					action: $action,
					case: $case,
					now: $now
				);
			}
		}

		// Stable on equal order, so two undeclared orders keep the manifest's order.
		usort($entries, static fn (array $left, array $right): int => $left['order'] <=> $right['order']);

		return array_map(
			static function (array $entry): array {
				unset($entry['order']);
				return $entry;
			},
			$entries
		);
	}//end resolve()

	/**
	 * Whether an action's `onCase` points at a case of this register and schema.
	 *
	 * @param array<string, mixed> $action   The normalised action.
	 * @param string               $register The case's register.
	 * @param string               $schema   The case's schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t03
	 */
	public function placedOn(array $action, string $register, string $schema): bool {
		$reference = $this->reference(action: $action);
		return ($action['type'] ?? '') === 'create'
			&& $reference !== null
			&& ($reference['register'] ?? '') === $register
			&& ($reference['schema'] ?? '') === $schema;
	}//end placedOn()

	/**
	 * The declared cross reference an action's `onCase` rides on, or null.
	 *
	 * @param array<string, mixed> $action The normalised action.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t03
	 */
	public function reference(array $action): ?array {
		$placement = ($action[OnCaseConfigNormaliser::KEY] ?? null);
		if (is_array($placement) === false) {
			return null;
		}

		$reference = ($action[CrossRefConfigNormaliser::KEY][(string)($placement['crossRef'] ?? '')] ?? null);
		if (is_array($reference) === false) {
			return null;
		}

		return $reference;
	}//end reference()

	/**
	 * Whether the action is open on this case now, and the last open day.
	 *
	 * Without a window the action is open. With one it opens the day after the
	 * `from` date and stays open through `days` days, to the end of that day
	 * in Europe/Amsterdam. A missing or unreadable date is closed.
	 *
	 * @param array<string, mixed>   $action The normalised action.
	 * @param array<string, mixed>   $case   The case row.
	 * @param DateTimeImmutable|null $now    The moment to judge at; now by default.
	 *
	 * @return array{open: bool, closesOn: string}
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-window-is-computed-on-the-server-req-coc-003
	 */
	public function window(array $action, array $case, ?DateTimeImmutable $now=null): array {
		$window = ($action[OnCaseConfigNormaliser::KEY]['window'] ?? null);
		if (is_array($window) === false) {
			return ['open' => true, 'closesOn' => ''];
		}

		$zone = new DateTimeZone(self::ZONE);
		$from = $this->dayOf(value: ($case[(string)($window['from'] ?? '')] ?? null), zone: $zone);
		if ($from === null) {
			return ['open' => false, 'closesOn' => ''];
		}

		$today    = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone)->format('Y-m-d');
		$closesOn = $from->modify('+' . (int)$window['days'] . ' days')->format('Y-m-d');

		return [
			'open' => ($today > $from->format('Y-m-d') && $today <= $closesOn),
			'closesOn' => $closesOn,
		];
	}//end window()

	/**
	 * The sentence a closed action shows: the app's own, or '' so the screen
	 * uses its translated default.
	 *
	 * @param array<string, mixed> $action The normalised action.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t03
	 */
	public function closedText(array $action): string {
		return (string)($action[OnCaseConfigNormaliser::KEY]['closedText'] ?? '');
	}//end closedText()

	/**
	 * One entry of `caseActions`, with its order still on it for sorting.
	 *
	 * @param string                 $app    The contributing app.
	 * @param array<string, mixed>   $action The normalised action.
	 * @param array<string, mixed>   $case   The case row.
	 * @param DateTimeImmutable|null $now    The moment to judge at.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(string $app, array $action, array $case, ?DateTimeImmutable $now): array {
		$window = $this->window(action: $action, case: $case, now: $now);
		$entry  = [
			'app' => $app,
			'action' => (string)($action['id'] ?? ''),
			'label' => (string)($action['label'] ?? ''),
			'open' => $window['open'],
			'crossRef' => (string)$action[OnCaseConfigNormaliser::KEY]['crossRef'],
			'order' => (int)($action[OnCaseConfigNormaliser::KEY]['order'] ?? 0),
		];
		if ($window['open'] === true && $window['closesOn'] !== '') {
			$entry['closesOn'] = $window['closesOn'];
		}

		if ($window['open'] === false) {
			$entry['closedText'] = $this->closedText(action: $action);
		}

		$form = $action;
		unset($form[OnCaseConfigNormaliser::KEY]);
		$entry['form'] = $form;

		return $entry;
	}//end entry()

	/**
	 * The calendar day a date value falls on in the zone, or null.
	 *
	 * @param mixed        $value The case's date value.
	 * @param DateTimeZone $zone  The zone days are counted in.
	 *
	 * @return DateTimeImmutable|null Midnight of that day, or null when unreadable.
	 */
	private function dayOf(mixed $value, DateTimeZone $zone): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			$moment = new DateTimeImmutable($value, $zone);
		} catch (\Exception) {
			return null;
		}

		return $moment->setTimezone($zone)->setTime(0, 0);
	}//end dayOf()
}//end class
