<?php

/**
 * Where a portal's accessibility measurements and its audit live: the
 * `accessibilityMeasurement` objects and three keys on the portal record
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
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use stdClass;

/**
 * Reads and writes through portaliq's OpenRegister reader and writer, scoped
 * by the portal's slug.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
 */
class AccessibilityMeasurements {

	public const REGISTER = 'portaliq';

	public const SCHEMA = 'accessibilityMeasurement';

	/**
	 * The page the measurement asks for to see the not-found page.
	 */
	public const NOT_FOUND_PROBE = '/deze-pagina-bestaat-niet';

	/**
	 * The most pages an administrator may add.
	 */
	public const MAX_ADDED_PAGES = 20;

	/**
	 * @param PortalObjectReader $reader The OpenRegister reader.
	 * @param PortalObjectWriter     $writer    The OpenRegister writer.
	 * @param AccessibilityStatement $statement The audit rules.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly AccessibilityStatement $statement = new AccessibilityStatement(),
	) {
	}//end __construct()

	/**
	 * One portal by slug, in any status, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	public function portalBySlug(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: 'portal',
			scopeField: 'slug',
			subjectRef: $slug,
			organisation: '',
			limit: 2
		);
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['slug'] ?? null) === $slug) {
				return $row;
			}
		}

		return null;
	}//end portalBySlug()

	/**
	 * The pages a run measures: home, search, the not-found probe, then the
	 * pages the administrator added.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return list<string> Site routes, each once.
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	public function pagesFor(array $portal): array {
		$search = trim((string)(((array)($portal['headerSearch'] ?? []))['route'] ?? ''));
		if (str_starts_with($search, '/') === false) {
			$search = '/zoeken';
		}

		$added = self::routes(pages: (array)($portal['accessibilityPages'] ?? []));

		return array_values(array_unique(array_merge(['/', $search, self::NOT_FOUND_PROBE], $added)));
	}//end pagesFor()

	/**
	 * Store one run.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 * @param array<string, mixed> $run    The normalised run (AccessibilityRun).
	 * @param string               $userId Who measured.
	 * @param DateTimeImmutable    $now    The clock.
	 *
	 * @return array<string, mixed>|null The stored measurement, or null when the write failed.
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	public function store(array $portal, array $run, string $userId, DateTimeImmutable $now): ?array {
		// The writer stamps `portal` too; naming it here keeps the record
		// whole whichever writer stores it.
		$data = [
			'portal' => (string)($portal['slug'] ?? ''),
			'measuredAt' => $now->format(DATE_ATOM),
			'measuredBy' => $userId,
		] + $run;

		return $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'portal',
			subjectRef: (string)($portal['slug'] ?? ''),
			organisation: '',
			data: $data
		);
	}//end store()

	/**
	 * The portal's newest measurement, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
	 */
	public function latest(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		$latest = null;
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'portal',
			subjectRef: $slug,
			organisation: '',
			limit: 200
		);
		foreach ($rows as $row) {
			if (is_array($row) === false || ($row['portal'] ?? null) !== $slug) {
				continue;
			}

			if ($latest === null || strcmp((string)($row['measuredAt'] ?? ''), (string)($latest['measuredAt'] ?? '')) > 0) {
				$latest = $row;
			}
		}

		return $latest;
	}//end latest()

	/**
	 * Record the audit, the register entry and the added pages on the
	 * portal. An audit that claims A or B must be complete and younger than
	 * three years, or nothing is saved.
	 *
	 * @param array<string, mixed>    $portal      The portal record.
	 * @param array<array-key, mixed> $audit       `{party, date, reportUrl, result}`.
	 * @param string                  $registerUrl The register entry.
	 * @param array<array-key, mixed> $pages       Added site routes.
	 * @param DateTimeImmutable       $now         The clock.
	 *
	 * @return array<string, mixed> `['error' => reason]`, or the saved portal.
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
	 */
	public function saveSettings(array $portal, array $audit, string $registerUrl, array $pages, DateTimeImmutable $now): array {
		$clean = self::auditOf(audit: $audit);
		if (in_array($clean['result'] ?? '', ['A', 'B'], true) === true) {
			$verdict = $this->statement->verdict(audit: $clean, now: $now);
			if ($verdict !== AccessibilityStatement::AUDIT_OK) {
				return ['error' => 'audit_'.$verdict];
			}
		}

		$registerUrl = trim($registerUrl);
		if ($registerUrl !== '' && $this->statement->https(url: $registerUrl) === '') {
			return ['error' => 'register_url_invalid'];
		}

		$saved = $this->writer->updateObject(
			register: self::REGISTER,
			schema: 'portal',
			scopeField: 'slug',
			subjectRef: (string)($portal['slug'] ?? ''),
			organisation: '',
			id: (string)($portal['id'] ?? $portal['uuid'] ?? ($portal['@self']['id'] ?? '')),
			data: [
				'accessibilityAudit' => self::asObject(audit: $clean),
				'accessibilityRegisterUrl' => $registerUrl,
				'accessibilityPages' => self::routes(pages: $pages),
			]
		);
		if ($saved === null) {
			return ['error' => 'save_failed'];
		}

		return $saved;
	}//end saveSettings()

	/**
	 * An empty audit as a JSON object, so the schema's `type: object` holds
	 * when an administrator clears it.
	 *
	 * @param array<string, string> $audit The clean audit.
	 *
	 * @return array<string, string>|stdClass
	 */
	private static function asObject(array $audit): array|stdClass {
		if ($audit === []) {
			return new stdClass();
		}

		return $audit;
	}//end asObject()

	/**
	 * The audit's four keys, each only when it says something.
	 *
	 * @param array<array-key, mixed> $audit The posted audit.
	 *
	 * @return array<string, string>
	 */
	private static function auditOf(array $audit): array {
		$clean = [];
		foreach (['party', 'date', 'reportUrl', 'result'] as $key) {
			$value = $audit[$key] ?? '';
			if (is_scalar($value) === false) {
				continue;
			}

			$value = trim((string)$value);
			if ($value !== '') {
				$clean[$key] = $value;
			}
		}

		if (isset($clean['result']) === true && in_array($clean['result'], AccessibilityStatement::RESULTS, true) === false) {
			unset($clean['result']);
		}

		return $clean;
	}//end auditOf()

	/**
	 * Site routes: strings that start with one slash, each once, at most
	 * twenty.
	 *
	 * @param array<array-key, mixed> $pages The posted routes.
	 *
	 * @return list<string>
	 */
	private static function routes(array $pages): array {
		$out = [];
		foreach ($pages as $page) {
			if (is_string($page) === false) {
				continue;
			}

			$route = trim($page);
			if (preg_match('#^/(?!/)\S{0,200}$#', $route) === 1 && in_array($route, $out, true) === false) {
				$out[] = $route;
			}
		}

		return array_slice($out, 0, self::MAX_ADDED_PAGES);
	}//end routes()
}//end class
