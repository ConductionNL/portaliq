<?php

/**
 * Contract of AccessibilityStatement
 *
 * The public surface of AccessibilityStatement that other components call, so they depend
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
 * Contract of AccessibilityStatement.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AccessibilityStatementInterface {

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
	public function build(array $portal, ?array $measurement, string $locale, DateTimeImmutable $now): array;

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
	public function verdict(array $audit, DateTimeImmutable $now): string;
}//end interface
