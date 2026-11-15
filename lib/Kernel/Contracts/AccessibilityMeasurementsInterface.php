<?php

/**
 * Contract of AccessibilityMeasurements
 *
 * The public surface of AccessibilityMeasurements that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of AccessibilityMeasurements.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AccessibilityMeasurementsInterface {

	/**
	 * The portal's newest measurement, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
	 */
	public function latest(string $slug): ?array;

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
	public function pagesFor(array $portal): array;

	/**
	 * One portal by slug, in any status, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-product-measures-its-own-pages-on-this-instance-req-sas-001
	 */
	public function portalBySlug(string $slug): ?array;

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
	public function saveSettings(array $portal, array $audit, string $registerUrl, array $pages, DateTimeImmutable $now): array;

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
	public function store(array $portal, array $run, string $userId, DateTimeImmutable $now): ?array;
}//end interface
