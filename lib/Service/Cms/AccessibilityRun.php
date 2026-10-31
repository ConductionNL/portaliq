<?php

/**
 * The shape of one accessibility measurement run as an administrator's
 * browser posts it (site-accessibility-statement REQ-SAS-001).
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
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Keeps only the keys the `accessibilityMeasurement` schema declares. A page
 * that was not measured keeps its reason and loses any violations, so it can
 * never read as a page without findings.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
class AccessibilityRun {

	/**
	 * @param AccessibilityStatement $statement The address rule.
	 */
	public function __construct(
		private readonly AccessibilityStatement $statement = new AccessibilityStatement(),
	) {
	}//end __construct()

	/**
	 * The most pages one run may carry.
	 */
	public const MAX_PAGES = 50;

	/**
	 * The reason kept when the browser gave none.
	 */
	public const NO_REASON = 'The page could not be measured.';

	/**
	 * The impacts axe-core reports.
	 */
	private const IMPACTS = ['minor', 'moderate', 'serious', 'critical'];

	/**
	 * Normalise a posted run.
	 *
	 * @param string                  $axeVersion The axe-core version.
	 * @param array<array-key, mixed> $tags       The rule sets.
	 * @param string                  $theme      The theme in use.
	 * @param array<array-key, mixed> $pages      Per page what was found.
	 *
	 * @return array<string, mixed> `['error' => reason]`, or the run's fields.
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	public function normalise(string $axeVersion, array $tags, string $theme, array $pages): array {
		$axeVersion = trim($axeVersion);
		if ($axeVersion === '' || strlen($axeVersion) > 40) {
			return ['error' => 'axe_version_missing'];
		}

		$rows = [];
		foreach ($pages as $page) {
			$row = $this->page(page: $page);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		if ($rows === [] || count($rows) > self::MAX_PAGES) {
			return ['error' => 'pages_invalid'];
		}

		return [
			'axeVersion' => $axeVersion,
			'tags' => array_values(array_unique(array_filter($tags, static fn ($tag): bool => is_string($tag) === true && $tag !== ''))),
			'theme' => trim($theme),
			'pages' => $rows,
		];
	}//end normalise()

	/**
	 * One page row, or null when it names no page.
	 *
	 * @param mixed $page The posted row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function page(mixed $page): ?array {
		if (is_array($page) === false) {
			return null;
		}

		$url = trim((string)($page['url'] ?? ''));
		if ($url === '' || strlen($url) > 500) {
			return null;
		}

		if (($page['measured'] ?? null) !== true) {
			$reason = trim((string)($page['reason'] ?? ''));
			if ($reason === '') {
				$reason = self::NO_REASON;
			}

			return ['url' => $url, 'measured' => false, 'reason' => mb_substr($reason, 0, 300)];
		}

		$violations = [];
		foreach ((array)($page['violations'] ?? []) as $violation) {
			$clean = $this->violation(violation: $violation);
			if ($clean !== null) {
				$violations[] = $clean;
			}
		}

		return ['url' => $url, 'measured' => true, 'violations' => $violations];
	}//end page()

	/**
	 * One violation, or null when it is not one axe-core reports.
	 *
	 * @param mixed $violation The posted violation.
	 *
	 * @return array<string, mixed>|null
	 */
	private function violation(mixed $violation): ?array {
		if (is_array($violation) === false) {
			return null;
		}

		$rule   = trim((string)($violation['rule'] ?? ''));
		$impact = (string)($violation['impact'] ?? '');
		if (preg_match('/^[a-z0-9-]{1,80}$/', $rule) !== 1 || in_array($impact, self::IMPACTS, true) === false) {
			return null;
		}

		$clean = ['rule' => $rule, 'impact' => $impact, 'nodes' => max(0, (int)($violation['nodes'] ?? 0))];
		$help  = trim((string)($violation['help'] ?? ''));
		if ($help !== '') {
			$clean['help'] = mb_substr($help, 0, 300);
		}

		$helpUrl = $this->statement->https(url: (string)($violation['helpUrl'] ?? ''));
		if ($helpUrl !== '') {
			$clean['helpUrl'] = $helpUrl;
		}

		return $clean;
	}//end violation()
}//end class
