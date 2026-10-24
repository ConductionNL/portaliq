<?php

/**
 * The accessibility statement of a portal, in the sections of the national
 * model, generated from the latest measurement and the recorded audit
 * (site-accessibility-statement).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use DateTimeImmutable;
use Throwable;

/**
 * A pure builder: the portal record, the latest measurement and the clock in,
 * the statement's model out. Status A or B comes only from a complete audit
 * younger than three years; without one the status is at most C and the
 * statement says the automated measurement does not establish compliance.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
 */
class AccessibilityStatement {

	/**
	 * The axe-core rule sets the measurement runs, the same five the e2e
	 * suite uses (tests/e2e/site-accessibility.spec.ts).
	 */
	public const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

	/**
	 * The results the national model knows.
	 */
	public const RESULTS = ['A', 'B', 'C', 'D'];

	/**
	 * How long an audit supports its result.
	 */
	public const AUDIT_YEARS = 3;

	/**
	 * Audit verdicts.
	 */
	public const AUDIT_OK         = 'ok';
	public const AUDIT_INCOMPLETE = 'incomplete';
	public const AUDIT_EXPIRED    = 'expired';

	/**
	 * Impact order, worst first.
	 */
	private const IMPACTS = ['critical' => 0, 'serious' => 1, 'moderate' => 2, 'minor' => 3];

	/**
	 * @param AccessibilityRuleSentences $sentences The rule table.
	 */
	public function __construct(
		private readonly AccessibilityRuleSentences $sentences = new AccessibilityRuleSentences(),
	) {
	}//end __construct()

	/**
	 * Build the statement.
	 *
	 * @param array<string, mixed>      $portal      The portal record.
	 * @param array<string, mixed>|null $measurement The latest measurement, or null.
	 * @param string                    $locale      The language of the sentences.
	 * @param DateTimeImmutable         $now         The clock.
	 *
	 * @return array<string, mixed> The statement model.
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
	 */
	public function build(array $portal, ?array $measurement, string $locale, DateTimeImmutable $now): array {
		$audit   = (array)($portal['accessibilityAudit'] ?? []);
		$verdict = self::auditVerdict(audit: $audit, now: $now);

		$status = null;
		if ($measurement !== null) {
			$status = 'C';
		}

		$supporting = null;
		if ($verdict === self::AUDIT_OK) {
			$supporting = [
				'party' => trim((string)$audit['party']),
				'date' => trim((string)$audit['date']),
				'reportUrl' => trim((string)$audit['reportUrl']),
				'result' => (string)$audit['result'],
			];
			$status     = $supporting['result'];
		}

		return [
			'organisation' => (string)($portal['organisation'] ?? ''),
			'website' => (string)($portal['title'] ?? ($portal['slug'] ?? '')),
			'status' => $status,
			'automatedOnly' => ($supporting === null),
			'audit' => $supporting,
			'auditExpired' => ($verdict === self::AUDIT_EXPIRED),
			'measurement' => $this->summary(measurement: $measurement),
			'issues' => $this->issues(measurement: $measurement, locale: $locale),
			'notMeasured' => $this->notMeasured(measurement: $measurement),
			'registerUrl' => self::httpsOrEmpty(url: (string)($portal['accessibilityRegisterUrl'] ?? '')),
			'contact' => $this->contactOf(portal: $portal),
		];
	}//end build()

	/**
	 * Where a visitor reports a barrier: the e-mail address and phone number
	 * of the portal's help details, each only when set.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array{email: string, phone: string}
	 */
	private function contactOf(array $portal): array {
		$help = (array)($portal['help'] ?? []);

		return [
			'email' => self::text(value: ($help['email'] ?? '')),
			'phone' => self::text(value: ($help['phone'] ?? '')),
		];
	}//end contactOf()

	/**
	 * A trimmed string, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()

	/**
	 * The audit verdict, for callers that hold a builder.
	 *
	 * @param array<array-key, mixed> $audit The audit as recorded.
	 * @param DateTimeImmutable       $now   The clock.
	 *
	 * @return string One of the AUDIT_* verdicts.
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
	 */
	public function verdict(array $audit, DateTimeImmutable $now): string {
		return self::auditVerdict(audit: $audit, now: $now);
	}//end verdict()

	/**
	 * An https address or '', for callers that hold a builder.
	 *
	 * @param string $url The address.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
	 */
	public function https(string $url): string {
		return self::httpsOrEmpty(url: $url);
	}//end https()

	/**
	 * Whether an audit supports its result: complete (party, date, an https
	 * report and a known result) and no older than three years.
	 *
	 * @param array<array-key, mixed> $audit The audit as recorded.
	 * @param DateTimeImmutable       $now   The clock.
	 *
	 * @return string One of the AUDIT_* verdicts.
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
	 */
	public static function auditVerdict(array $audit, DateTimeImmutable $now): string {
		$party  = trim((string)($audit['party'] ?? ''));
		$report = self::httpsOrEmpty(url: (string)($audit['reportUrl'] ?? ''));
		$result = (string)($audit['result'] ?? '');
		$date   = self::dateOf(value: (string)($audit['date'] ?? ''));
		if ($party === '' || $report === '' || in_array($result, self::RESULTS, true) === false || $date === null || $date > $now) {
			return self::AUDIT_INCOMPLETE;
		}

		if ($date < $now->modify('-'.self::AUDIT_YEARS.' years')) {
			return self::AUDIT_EXPIRED;
		}

		return self::AUDIT_OK;
	}//end auditVerdict()

	/**
	 * The measurement's facts for the evidence section.
	 *
	 * @param array<string, mixed>|null $measurement The measurement.
	 *
	 * @return array<string, mixed>|null
	 */
	private function summary(?array $measurement): ?array {
		if ($measurement === null) {
			return null;
		}

		$measured = 0;
		$skipped  = 0;
		foreach ($this->pagesOf(measurement: $measurement) as $page) {
			if (($page['measured'] ?? false) === true) {
				$measured++;
				continue;
			}

			$skipped++;
		}

		return [
			'measuredAt' => (string)($measurement['measuredAt'] ?? ''),
			'axeVersion' => (string)($measurement['axeVersion'] ?? ''),
			'tags' => array_values(array_filter((array)($measurement['tags'] ?? []), 'is_string')),
			'theme' => (string)($measurement['theme'] ?? ''),
			'pagesMeasured' => $measured,
			'pagesNotMeasured' => $skipped,
		];
	}//end summary()

	/**
	 * Per violated rule: the sentence, the worst impact and the number of
	 * pages, the worst first.
	 *
	 * @param array<string, mixed>|null $measurement The measurement.
	 * @param string                    $locale      The language.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function issues(?array $measurement, string $locale): array {
		if ($measurement === null) {
			return [];
		}

		$byRule = [];
		foreach ($this->pagesOf(measurement: $measurement) as $page) {
			if (($page['measured'] ?? false) !== true) {
				continue;
			}

			foreach ($this->rulesOnPage(page: $page) as $rule => $violation) {
				$byRule[$rule] = $this->merge(known: ($byRule[$rule] ?? null), violation: $violation);
			}
		}

		$issues = [];
		foreach ($byRule as $rule => $entry) {
			$issues[] = [
				'rule' => $rule,
				'sentence' => $this->sentences->sentence(rule: $rule, locale: $locale, fallback: $entry['help']),
				'impact' => $entry['impact'],
				'pages' => $entry['pages'],
				'helpUrl' => $entry['helpUrl'],
			];
		}

		usort($issues, static fn (array $one, array $two): int => (self::rank(issue: $one) <=> self::rank(issue: $two)));

		return $issues;
	}//end issues()

	/**
	 * The well-formed violations of one page, one per rule.
	 *
	 * @param array<array-key, mixed> $page The page row.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function rulesOnPage(array $page): array {
		$rules = [];
		foreach ((array)($page['violations'] ?? []) as $violation) {
			if (is_array($violation) === false) {
				continue;
			}

			$rule   = trim((string)($violation['rule'] ?? ''));
			$impact = (string)($violation['impact'] ?? '');
			if ($rule === '' || isset(self::IMPACTS[$impact]) === false) {
				continue;
			}

			$rules[$rule] = [
				'impact' => $impact,
				'help' => (string)($violation['help'] ?? ''),
				'helpUrl' => self::httpsOrEmpty(url: (string)($violation['helpUrl'] ?? '')),
			];
		}

		return $rules;
	}//end rulesOnPage()

	/**
	 * One more page for a rule: count it and keep the worst impact.
	 *
	 * @param array<string, mixed>|null $known     What is known of the rule so far.
	 * @param array<string, string>     $violation The rule on this page.
	 *
	 * @return array<string, mixed>
	 */
	private function merge(?array $known, array $violation): array {
		if ($known === null) {
			return $violation + ['pages' => 1];
		}

		$known['pages']++;
		if (self::IMPACTS[$violation['impact']] < self::IMPACTS[$known['impact']]) {
			$known['impact'] = $violation['impact'];
		}

		return $known;
	}//end merge()

	/**
	 * The pages that could not be measured, with the reason.
	 *
	 * @param array<string, mixed>|null $measurement The measurement.
	 *
	 * @return list<array{url: string, reason: string}>
	 */
	private function notMeasured(?array $measurement): array {
		if ($measurement === null) {
			return [];
		}

		$out = [];
		foreach ($this->pagesOf(measurement: $measurement) as $page) {
			if (($page['measured'] ?? false) === true) {
				continue;
			}

			$out[] = ['url' => (string)($page['url'] ?? ''), 'reason' => (string)($page['reason'] ?? '')];
		}

		return $out;
	}//end notMeasured()

	/**
	 * The sort key of an issue: worst impact, most pages, then the rule.
	 *
	 * @param array<string, mixed> $issue The issue.
	 *
	 * @return array{0: int, 1: int, 2: string}
	 */
	private static function rank(array $issue): array {
		return [self::IMPACTS[$issue['impact']], -$issue['pages'], (string)$issue['rule']];
	}//end rank()

	/**
	 * The page rows that are arrays.
	 *
	 * @param array<string, mixed> $measurement The measurement.
	 *
	 * @return list<array<array-key, mixed>>
	 */
	private function pagesOf(array $measurement): array {
		return array_values(array_filter((array)($measurement['pages'] ?? []), 'is_array'));
	}//end pagesOf()

	/**
	 * An https address, or ''.
	 *
	 * @param string $url The address.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
	 */
	public static function httpsOrEmpty(string $url): string {
		$url = trim($url);
		if (preg_match('#^https://[^\s/]+#', $url) !== 1) {
			return '';
		}

		return $url;
	}//end httpsOrEmpty()

	/**
	 * A YYYY-MM-DD date, or null.
	 *
	 * @param string $value The date.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function dateOf(string $value): ?DateTimeImmutable {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
			return null;
		}

		try {
			return new DateTimeImmutable(trim($value).'T00:00:00+00:00');
		} catch (Throwable) {
			return null;
		}
	}//end dateOf()
}//end class
