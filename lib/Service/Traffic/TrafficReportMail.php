<?php

/**
 * Portaliq Traffic Report Mail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use OCP\IL10N;

/**
 * The words of a report mail: a subject and the lines of each section,
 * with every number set against the period before. Pure, so a test can
 * read the exact figures a recipient would.
 *
 * Lines, not tables. The mail template every Nextcloud instance ships
 * renders headings, paragraphs and one button, and a report a
 * communications officer reads on a phone is better as "Page views:
 * 1,240 (+12% on the week before)" than as a table that wraps.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */
class TrafficReportMail {

	/**
	 * Constructor.
	 *
	 * @param TrafficReportNumbers $numbers The percent-change arithmetic.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficReportNumbers $numbers = new TrafficReportNumbers(),
	) {
	}

	/**
	 * The subject line.
	 *
	 * @param IL10N                $l      The recipient's language.
	 * @param array<string, mixed> $portal The portal record.
	 * @param array<string, mixed> $report The resolved report.
	 * @param array<string, mixed> $period The period.
	 *
	 * @return string The subject.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
	 */
	public function subject(IL10N $l, array $portal, array $report, array $period): string {
		return $l->t('%1$s: %2$s (%3$s)', [
			(string)($portal['title'] ?? $portal['slug'] ?? ''),
			(string)$report['name'],
			$this->span(l: $l, period: $period),
		]);
	}

	/**
	 * The sections, each a heading and its lines.
	 *
	 * @param IL10N                $l        The recipient's language.
	 * @param array<string, mixed> $report   The resolved report.
	 * @param array<string, mixed> $period   The period.
	 * @param array<string, mixed> $current  The period's folded numbers.
	 * @param array<string, mixed> $previous The previous period's folded numbers.
	 *
	 * @return array<int, array{heading: string, lines: string[]}> The sections.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
	 */
	public function sections(IL10N $l, array $report, array $period, array $current, array $previous): array {
		$out = [];
		foreach ((array)($report['sections'] ?? []) as $section) {
			$lines = match ((string)$section) {
				'overview' => $this->overview(l: $l, current: $current, previous: $previous),
				'pages' => $this->ranked(l: $l, rows: (array)($current['pages'] ?? []), label: 'path', count: 'views', empty: $l->t('No pages viewed.')),
				'sources' => $this->map(l: $l, map: (array)($current['sources'] ?? []), empty: $l->t('No referrers recorded.')),
				'visitors' => $this->visitors(l: $l, current: $current),
				'goals' => $this->goals(l: $l, current: $current, previous: $previous),
				'funnels' => $this->funnels(l: $l, current: $current),
				'forms' => $this->forms(l: $l, current: $current),
				default => [],
			};
			if ($lines === []) {
				continue;
			}

			$out[] = ['heading' => $this->heading(l: $l, section: (string)$section), 'lines' => $lines];
		}

		$out[] = [
			'heading' => $l->t('Period'),
			'lines' => [
				$l->t('This period: %1$s to %2$s.', [(string)$period['from'], (string)$period['to']]),
				$l->t('Compared with: %1$s to %2$s.', [(string)$period['previousFrom'], (string)$period['previousTo']]),
			],
		];

		return $out;
	}

	/**
	 * The sections as plain text.
	 *
	 * @param array<int, array{heading: string, lines: string[]}> $sections The sections.
	 * @param string                                             $link     The Traffic page.
	 * @param string                                             $linkText What the link says.
	 *
	 * @return string The text body.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
	 */
	public function plain(array $sections, string $link, string $linkText): string {
		$out = [];
		foreach ($sections as $section) {
			$out[] = $section['heading'];
			$out[] = str_repeat('-', mb_strlen($section['heading']));
			foreach ($section['lines'] as $line) {
				$out[] = $line;
			}

			$out[] = '';
		}

		$out[] = $linkText . ': ' . $link;

		return implode("\n", $out) . "\n";
	}

	/**
	 * The four headline numbers against the period before.
	 *
	 * @param IL10N                $l        The language.
	 * @param array<string, mixed> $current  The current numbers.
	 * @param array<string, mixed> $previous The previous numbers.
	 *
	 * @return string[] The lines.
	 */
	private function overview(IL10N $l, array $current, array $previous): array {
		$lines = [];
		foreach ([
			'pageViews' => $l->t('Page views'),
			'sessions' => $l->t('Sessions'),
			'visitors' => $l->t('Visitors'),
			'engagedSessions' => $l->t('Engaged sessions'),
		] as $key => $label) {
			$lines[] = $this->compared(l: $l, label: $label, current: (float)($current[$key] ?? 0), previous: (float)($previous[$key] ?? 0));
		}

		$lines[] = $l->t('Bounce rate: %s%%', [$this->percent((float)($current['bounceRate'] ?? 0))]);

		return $lines;
	}

	/**
	 * "Label: 1240 (+12% on the previous period)".
	 *
	 * @param IL10N  $l        The language.
	 * @param string $label    The metric's name.
	 * @param float  $current  The current value.
	 * @param float  $previous The previous value.
	 *
	 * @return string The line.
	 */
	private function compared(IL10N $l, string $label, float $current, float $previous): string {
		$change = $this->numbers->change(current: $current, previous: $previous);
		$value = $this->number($current);
		if ($change === null) {
			return $l->t('%1$s: %2$s (no figure for the previous period)', [$label, $value]);
		}

		$sign = ($change >= 0) ? '+' : '';

		return $l->t('%1$s: %2$s (%3$s%% on the previous period, %4$s)', [$label, $value, $sign . $this->number($change), $this->number($previous)]);
	}

	/**
	 * A ranked list as "value: count" lines.
	 *
	 * @param IL10N                            $l     The language.
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 * @param string                           $label The label field.
	 * @param string                           $count The count field.
	 * @param string                           $empty What to say when there are none.
	 *
	 * @return string[] The lines.
	 */
	private function ranked(IL10N $l, array $rows, string $label, string $count, string $empty): array {
		if ($rows === []) {
			return [$empty];
		}

		$lines = [];
		foreach ($rows as $row) {
			$lines[] = $l->t('%1$s: %2$s', [(string)($row[$label] ?? ''), $this->number((float)($row[$count] ?? 0))]);
		}

		return $lines;
	}

	/**
	 * A value-to-count map as lines, highest first.
	 *
	 * @param IL10N                 $l     The language.
	 * @param array<string, mixed>  $map   The map.
	 * @param string                $empty What to say when it is empty.
	 *
	 * @return string[] The lines.
	 */
	private function map(IL10N $l, array $map, string $empty): array {
		if ($map === []) {
			return [$empty];
		}

		arsort($map);
		$lines = [];
		foreach (array_slice($map, 0, 10, true) as $value => $count) {
			$lines[] = $l->t('%1$s: %2$s', [(string)$value, $this->number((float)$count)]);
		}

		return $lines;
	}

	/**
	 * The device, browser, language and region breakdowns.
	 *
	 * @param IL10N                $l       The language.
	 * @param array<string, mixed> $current The current numbers.
	 *
	 * @return string[] The lines.
	 */
	private function visitors(IL10N $l, array $current): array {
		$lines = [];
		foreach ([
			'devices' => $l->t('Devices'),
			'browsers' => $l->t('Browsers'),
			'languages' => $l->t('Languages'),
			'regions' => $l->t('Regions'),
		] as $key => $label) {
			$map = (array)($current[$key] ?? []);
			if ($map === []) {
				continue;
			}

			arsort($map);
			$parts = [];
			foreach (array_slice($map, 0, 5, true) as $value => $count) {
				$parts[] = (string)$value . ' ' . $this->number((float)$count);
			}

			$lines[] = $label . ': ' . implode(', ', $parts);
		}

		if ($lines === []) {
			return [$l->t('No visitor breakdown is measured for this portal.')];
		}

		return $lines;
	}

	/**
	 * The goals with their conversions, and the conversion rate.
	 *
	 * @param IL10N                $l        The language.
	 * @param array<string, mixed> $current  The current numbers.
	 * @param array<string, mixed> $previous The previous numbers.
	 *
	 * @return string[] The lines.
	 */
	private function goals(IL10N $l, array $current, array $previous): array {
		$rows = (array)($current['goals'] ?? []);
		if ($rows === []) {
			return [$l->t('No goals are defined for this portal.')];
		}

		$before = [];
		foreach ((array)($previous['goals'] ?? []) as $goal) {
			$before[(string)($goal['id'] ?? '')] = (float)($goal['conversions'] ?? 0);
		}

		$lines = [];
		foreach ($rows as $goal) {
			$lines[] = $this->compared(
				l: $l,
				label: (string)($goal['name'] ?? $goal['id'] ?? ''),
				current: (float)($goal['conversions'] ?? 0),
				previous: (float)($before[(string)($goal['id'] ?? '')] ?? 0)
			);
		}

		$lines[] = $l->t('Conversion rate: %s%%', [$this->percent((float)($current['conversionRate'] ?? 0))]);

		return $lines;
	}

	/**
	 * Each funnel's steps with sessions and drop-off.
	 *
	 * @param IL10N                $l       The language.
	 * @param array<string, mixed> $current The current numbers.
	 *
	 * @return string[] The lines.
	 */
	private function funnels(IL10N $l, array $current): array {
		$rows = (array)($current['funnels'] ?? []);
		if ($rows === []) {
			return [$l->t('No funnels are defined for this portal.')];
		}

		$lines = [];
		foreach ($rows as $funnel) {
			$steps = [];
			foreach ((array)($funnel['steps'] ?? []) as $step) {
				$steps[] = (string)($step['name'] ?? '') . ' ' . $this->number((float)($step['sessions'] ?? 0));
			}

			$lines[] = (string)($funnel['name'] ?? $funnel['id'] ?? '') . ': ' . implode(' > ', $steps);
		}

		return $lines;
	}

	/**
	 * Each form's starts, submits and abandons.
	 *
	 * @param IL10N                $l       The language.
	 * @param array<string, mixed> $current The current numbers.
	 *
	 * @return string[] The lines.
	 */
	private function forms(IL10N $l, array $current): array {
		$rows = (array)($current['forms'] ?? []);
		if ($rows === []) {
			return [$l->t('No form activity was recorded.')];
		}

		$lines = [];
		foreach ($rows as $form) {
			$lines[] = $l->t('%1$s: %2$s started, %3$s submitted, %4$s abandoned', [
				(string)($form['formId'] ?? ''),
				$this->number((float)($form['starts'] ?? 0)),
				$this->number((float)($form['submits'] ?? 0)),
				$this->number((float)($form['abandons'] ?? 0)),
			]);
		}

		return $lines;
	}

	/**
	 * A section's heading.
	 *
	 * @param IL10N  $l       The language.
	 * @param string $section The section id.
	 *
	 * @return string The heading.
	 */
	private function heading(IL10N $l, string $section): string {
		return match ($section) {
			'pages' => $l->t('Pages'),
			'sources' => $l->t('Sources'),
			'visitors' => $l->t('Visitors'),
			'goals' => $l->t('Goals'),
			'funnels' => $l->t('Funnels'),
			'forms' => $l->t('Forms'),
			default => $l->t('Overview'),
		};
	}

	/**
	 * "2026-09-01 to 2026-09-07", or the single day.
	 *
	 * @param IL10N                $l      The language.
	 * @param array<string, mixed> $period The period.
	 *
	 * @return string The span.
	 */
	private function span(IL10N $l, array $period): string {
		if (($period['from'] ?? '') === ($period['to'] ?? '')) {
			return (string)($period['from'] ?? '');
		}

		return $l->t('%1$s to %2$s', [(string)($period['from'] ?? ''), (string)($period['to'] ?? '')]);
	}

	/**
	 * A number without trailing zeros.
	 *
	 * @param float $value The value.
	 *
	 * @return string The number.
	 */
	private function number(float $value): string {
		if (floor($value) === $value) {
			return (string)(int)$value;
		}

		return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
	}

	/**
	 * A share as a percentage with one decimal.
	 *
	 * @param float $share Between 0 and 1.
	 *
	 * @return string The percentage.
	 */
	private function percent(float $share): string {
		return $this->number(round($share * 100, 1));
	}
}
