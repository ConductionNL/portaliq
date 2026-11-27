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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Kernel\Contracts\AccessibilityRunInterface;

/**
 * Keeps only the keys the `accessibilityMeasurement` schema declares. A page
 * that was not measured keeps its reason and loses any violations, so it can
 * never read as a page without findings.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
class AccessibilityRun implements AccessibilityRunInterface {

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
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	public function normalise(string $axeVersion, array $tags, string $theme, array $pages): array {
		$axeVersion = trim($axeVersion);
		if ($axeVersion === '' || strlen($axeVersion) > 40) {
			return ['error' => 'axe_version_missing'];
		}

		$rows = $this->rowsOf(pages: $pages);
		if ($rows === [] || count($rows) > self::MAX_PAGES) {
			return ['error' => 'pages_invalid'];
		}

		return $this->run(axeVersion: $axeVersion, tags: $tags, theme: $theme, rows: $rows);
	}//end normalise()

	/**
	 * The page rows that name a page.
	 *
	 * @param array<array-key, mixed> $pages Per page what was found.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsOf(array $pages): array {
		$rows = [];
		foreach ($pages as $page) {
			$row = $this->page(page: $page);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * The run's fields.
	 *
	 * @param string                           $axeVersion The axe-core version.
	 * @param array<array-key, mixed>          $tags       The rule sets.
	 * @param string                           $theme      The theme in use.
	 * @param array<int, array<string, mixed>> $rows       The page rows.
	 *
	 * @return array<string, mixed>
	 */
	private function run(string $axeVersion, array $tags, string $theme, array $rows): array {
		return [
			'axeVersion' => $axeVersion,
			'tags' => array_values(array_unique(array_filter($tags, static fn ($tag): bool => is_string($tag) === true && $tag !== ''))),
			'theme' => trim($theme),
			'pages' => $rows,
		];
	}//end run()

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

		$url = $this->urlOf(page: $page);
		if ($url === null) {
			return null;
		}

		if (($page['measured'] ?? null) !== true) {
			return $this->unmeasured(url: $url, page: $page);
		}

		return ['url' => $url, 'measured' => true, 'violations' => $this->violationsOf(page: $page)];
	}//end page()

	/**
	 * The page's address, or null when it is missing or too long.
	 *
	 * @param array<array-key, mixed> $page The posted row.
	 *
	 * @return string|null
	 */
	private function urlOf(array $page): string|null {
		$url = trim((string)($page['url'] ?? ''));
		if ($url === '' || strlen($url) > 500) {
			return null;
		}

		return $url;
	}//end urlOf()

	/**
	 * The row of a page that was not measured, with why.
	 *
	 * @param string                  $url  The page's address.
	 * @param array<array-key, mixed> $page The posted row.
	 *
	 * @return array<string, mixed>
	 */
	private function unmeasured(string $url, array $page): array {
		$reason = trim((string)($page['reason'] ?? ''));
		if ($reason === '') {
			$reason = self::NO_REASON;
		}

		return ['url' => $url, 'measured' => false, 'reason' => mb_substr($reason, 0, 300)];
	}//end unmeasured()

	/**
	 * The page's violations that axe-core reports.
	 *
	 * @param array<array-key, mixed> $page The posted row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function violationsOf(array $page): array {
		$violations = [];
		foreach ((array)($page['violations'] ?? []) as $violation) {
			$clean = $this->violation(violation: $violation);
			if ($clean !== null) {
				$violations[] = $clean;
			}
		}

		return $violations;
	}//end violationsOf()

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

		return $this->withHelp(clean: $clean, violation: $violation);
	}//end violation()

	/**
	 * The violation with its help text and a safe help address, when given.
	 *
	 * @param array<string, mixed>    $clean     The violation so far.
	 * @param array<array-key, mixed> $violation The posted violation.
	 *
	 * @return array<string, mixed>
	 */
	private function withHelp(array $clean, array $violation): array {
		$help = trim((string)($violation['help'] ?? ''));
		if ($help !== '') {
			$clean['help'] = mb_substr($help, 0, 300);
		}

		$helpUrl = $this->statement->https(url: (string)($violation['helpUrl'] ?? ''));
		if ($helpUrl !== '') {
			$clean['helpUrl'] = $helpUrl;
		}

		return $clean;
	}//end withHelp()
}//end class
