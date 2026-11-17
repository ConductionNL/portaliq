<?php

/**
 * CaseActionsResolver: the create actions a contribution places on a case page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-case-page-offers-the-actions-the-server-lists-req-coc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Contribution\OnCaseConfigNormaliser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use Throwable;

/**
 * Lists the create actions whose `onCase` placement points at a case's collection, and
 * decides on the server whether each is still open on that case.
 *
 * A window opens the day after the case's `from` date and closes at the end of the last of
 * `days` days, Europe/Amsterdam. A missing or unreadable date reads as closed.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-window-is-computed-on-the-server-req-coc-003
 */
class CaseActionsResolver {

	/**
	 * The zone a window's days are counted in.
	 *
	 * @var string
	 */
	public const ZONE = 'Europe/Amsterdam';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock.
	 * @param IL10N        $l10n Translations, for the default closed sentence.
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-case-page-offers-the-actions-the-server-lists-req-coc-002
	 */
	public function __construct(
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The case actions of the resident's contributions for one case, in declared order.
	 *
	 * @param array<mixed>        $contributions The resident's contributions.
	 * @param string              $register      The case's register.
	 * @param string              $schema        The case's schema.
	 * @param array<string,mixed> $case          The case as read for the resident.
	 *
	 * @return list<array<string,mixed>> Each `{app, action, label, open, closesOn?, closedText?}`.
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-case-page-offers-the-actions-the-server-lists-req-coc-002
	 */
	public function forCase(array $contributions, string $register, string $schema, array $case): array {
		$found = [];
		foreach ($contributions as $contribution) {
			if (is_array($contribution) === false) {
				continue;
			}

			foreach ((array)($contribution['actions'] ?? []) as $action) {
				if (is_array($action) === true && $this->crossRefField(action: $action, register: $register, schema: $schema) !== null) {
					$found[] = $this->entry(app: (string)($contribution['app'] ?? ''), action: $action, case: $case);
				}
			}
		}

		usort($found, static fn (array $a, array $b): int => ($a['order'] <=> $b['order']));

		return array_map(
			static function (array $entry): array {
				unset($entry['order']);
				return $entry;
			},
			$found
		);
	}//end forCase()

	/**
	 * The cross-reference field a create action fills with the case, when its `onCase`
	 * placement points at this collection; null otherwise.
	 *
	 * @param array<string,mixed> $action   The action, normalised.
	 * @param string              $register The case's register.
	 * @param string              $schema   The case's schema.
	 *
	 * @return string|null The field name.
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004
	 */
	public function crossRefField(array $action, string $register, string $schema): ?string {
		$onCase = ($action[OnCaseConfigNormaliser::KEY] ?? null);
		if (($action['type'] ?? null) !== 'create' || is_array($onCase) === false) {
			return null;
		}

		$field     = (string)($onCase['crossRef'] ?? '');
		$reference = ($action['crossRefs'][$field] ?? null);
		if ($field === '' || is_array($reference) === false) {
			return null;
		}

		if (($reference['register'] ?? null) !== $register || ($reference['schema'] ?? null) !== $schema) {
			return null;
		}

		return $field;
	}//end crossRefField()

	/**
	 * Whether the action is open on the case, and the last day it is.
	 *
	 * @param array<string,mixed> $action The action, normalised.
	 * @param array<string,mixed> $case   The case.
	 *
	 * @return array{open: bool, closesOn?: string}
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-window-is-computed-on-the-server-req-coc-003
	 */
	public function window(array $action, array $case): array {
		$window = ($action[OnCaseConfigNormaliser::KEY]['window'] ?? null);
		if (is_array($window) === false) {
			return ['open' => true];
		}

		$from = $this->day(value: ($case[(string)($window['from'] ?? '')] ?? null));
		$days = (int)($window['days'] ?? 0);
		if ($from === null || $days < 1) {
			return ['open' => false];
		}

		$first = $from->modify('+1 day')->format('Y-m-d');
		$last  = $from->modify('+' . $days . ' days')->format('Y-m-d');
		$today = $this->today();

		return ['open' => ($today >= $first && $today <= $last), 'closesOn' => $last];
	}//end window()

	/**
	 * The sentence a closed action shows: the app's own, else the default.
	 *
	 * @param array<string,mixed> $action The action, normalised.
	 *
	 * @return string The sentence.
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-window-is-computed-on-the-server-req-coc-003
	 */
	public function closedText(array $action): string {
		$text = ($action[OnCaseConfigNormaliser::KEY]['closedText'] ?? null);
		if (is_string($text) === true && trim($text) !== '') {
			return $text;
		}

		return $this->l10n->t('You can no longer do this on this case.');
	}//end closedText()

	/**
	 * One listed case action.
	 *
	 * @param string              $app    The contributing app.
	 * @param array<string,mixed> $action The action.
	 * @param array<string,mixed> $case   The case.
	 *
	 * @return array<string,mixed> The entry, with its `order` for sorting.
	 */
	private function entry(string $app, array $action, array $case): array {
		$window = $this->window(action: $action, case: $case);
		$entry  = [
			'app' => $app,
			'action' => (string)($action['id'] ?? ''),
			'label' => (string)($action['label'] ?? ''),
			'open' => $window['open'],
			'order' => (int)($action[OnCaseConfigNormaliser::KEY]['order'] ?? 0),
		];
		if (isset($window['closesOn']) === true) {
			$entry['closesOn'] = $window['closesOn'];
		}

		if ($window['open'] === false) {
			$entry['closedText'] = $this->closedText(action: $action);
		}

		return $entry;
	}//end entry()

	/**
	 * The calendar day of a date or date-time value in Europe/Amsterdam, or null when it is
	 * missing or unreadable.
	 *
	 * @param mixed $value The case's date value.
	 *
	 * @return DateTimeImmutable|null Midnight of that day.
	 */
	private function day(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$zone = new DateTimeZone(self::ZONE);
		try {
			$moment = new DateTimeImmutable(trim($value), $zone);
		} catch (Throwable) {
			return null;
		}

		return $moment->setTimezone($zone)->setTime(0, 0);
	}//end day()

	/**
	 * Today in Europe/Amsterdam, as Y-m-d.
	 *
	 * @return string The day.
	 */
	private function today(): string {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d');
	}//end today()
}//end class
