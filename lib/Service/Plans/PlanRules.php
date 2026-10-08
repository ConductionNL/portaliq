<?php

/**
 * Portaliq Plan Rules
 *
 * The dates and counts of a shared plan, without any reading or writing.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Plans;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure rules: a template becomes dates, a plan is near its end or not, a done
 * plan stays visible for a year.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/shared-plans/spec.md
 */
class PlanRules {

	/**
	 * How many days before the end date a plan asks for action.
	 *
	 * @var int
	 */
	public const WARN_DAYS = 14;

	/**
	 * How long a done plan stays in the list, in days.
	 *
	 * @var int
	 */
	public const DONE_VISIBLE_DAYS = 365;

	/**
	 * The most actions a template may start a plan with.
	 *
	 * @var int
	 */
	public const MAX_TEMPLATE_ACTIONS = 20;

	/**
	 * Expand a template into an end date and actions with end dates, all
	 * counted from the day the plan starts.
	 *
	 * @param array<string, mixed> $template The template row.
	 * @param string $today The start day, `Y-m-d`.
	 *
	 * @return array{endDate: string, actions: array<int, array{title: string, kind: string, endDate: string}>} The dates.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	public static function expand(array $template, string $today): array {
		$duration = (int)($template['durationDays'] ?? 56);
		if ($duration < 1) {
			$duration = 56;
		}

		$actions = [];
		$declared = $template['actions'] ?? [];
		if (is_array($declared) === false) {
			$declared = [];
		}

		foreach ($declared as $action) {
			$title = '';
			if (is_array($action) === true && is_string($action['title'] ?? null) === true) {
				$title = trim($action['title']);
			}

			if ($title === '' || count($actions) >= self::MAX_TEMPLATE_ACTIONS) {
				continue;
			}

			$kind = 'once';
			if (($action['kind'] ?? '') === 'recurring') {
				$kind = 'recurring';
			}

			$offset    = max(0, (int)($action['offsetDays'] ?? 0));
			$actions[] = ['title' => mb_substr($title, 0, 200), 'kind' => $kind, 'endDate' => self::addDays(day: $today, days: $offset)];
		}

		return ['endDate' => self::addDays(day: $today, days: $duration), 'actions' => $actions];
	}//end expand()

	/**
	 * A day some days later.
	 *
	 * @param string $day The day, `Y-m-d`.
	 * @param int $days How many days to add.
	 *
	 * @return string The day, `Y-m-d`.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	public static function addDays(string $day, int $days): string {
		return (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+'.$days.' days')->format('Y-m-d');
	}//end addDays()

	/**
	 * The whole days from today to the end date: negative once it has passed,
	 * null without a valid end date.
	 *
	 * @param string $endDate The end date, `Y-m-d` or a date-time.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return int|null The days left.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t05
	 */
	public static function daysLeft(string $endDate, string $today): ?int {
		$end = substr($endDate, 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
			return null;
		}

		$from = new DateTimeImmutable($today, new DateTimeZone('UTC'));
		$to   = new DateTimeImmutable($end, new DateTimeZone('UTC'));

		return (int)$from->diff($to)->format('%r%a');
	}//end daysLeft()

	/**
	 * Whether a plan is asking for action: running, 14 days or less from its
	 * end date (and not past it), with open actions.
	 *
	 * @param array<string, mixed> $plan The plan row.
	 * @param int $openActions How many of its actions are not done.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t05
	 */
	public static function needsAction(array $plan, int $openActions, string $today): bool {
		$left = self::daysLeft(endDate: (string)($plan['endDate'] ?? ''), today: $today);

		return ($plan['status'] ?? 'running') === 'running' && $openActions > 0 && $left !== null && $left >= 0 && $left <= self::WARN_DAYS;
	}//end needsAction()

	/**
	 * Whether the end reminder is due: the plan needs action and none was sent
	 * for this end date (changing the end date clears the mark).
	 *
	 * @param array<string, mixed> $plan The plan row.
	 * @param int $openActions How many of its actions are not done.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t05
	 */
	public static function reminderDue(array $plan, int $openActions, string $today): bool {
		return self::needsAction(plan: $plan, openActions: $openActions, today: $today) === true && trim((string)($plan['endReminderSentAt'] ?? '')) === '';
	}//end reminderDue()

	/**
	 * Whether a plan still shows in the list: a done plan goes a year after it was done.
	 *
	 * @param array<string, mixed> $plan The plan row.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t08
	 */
	public static function visible(array $plan, string $today): bool {
		if (($plan['status'] ?? 'running') !== 'done') {
			return true;
		}

		$ago = self::daysLeft(endDate: substr((string)($plan['doneAt'] ?? ''), 0, 10), today: $today);
		if ($ago === null) {
			return true;
		}

		return (-$ago) <= self::DONE_VISIBLE_DAYS;
	}//end visible()

	/**
	 * The state a plan card shows: done, needing action, or running.
	 *
	 * @param array<string, mixed> $plan The plan row.
	 * @param int $openActions How many actions are open.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return string `done`, `action` or `running`.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	public static function stateOf(array $plan, int $openActions, string $today): string {
		if (($plan['status'] ?? 'running') === 'done') {
			return 'done';
		}

		if (self::needsAction(plan: $plan, openActions: $openActions, today: $today) === true) {
			return 'action';
		}

		return 'running';
	}//end stateOf()
}//end class
