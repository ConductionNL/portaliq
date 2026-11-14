<?php

/**
 * Contract of FormStatements
 *
 * The public surface of FormStatements that other components call, so they depend
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

/**
 * Contract of FormStatements.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FormStatementsInterface {

	/**
	 * Check what the resident accepted against what the form asks.
	 *
	 * A required statement that was not accepted, or that has no text to show,
	 * is an error; a statement accepted that the form does not ask is ignored,
	 * so nothing the client invents is recorded.
	 *
	 * @param array<int, array{key: string, required: bool, text: string, version: string}> $asked    The asked statements.
	 * @param mixed                                                                         $accepted The keys the client says were ticked.
	 *
	 * @return array{errors: array<string, string>, record: array<int, array{key: string, textVersion: string, acceptedAt: string}>}
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
	 */
	public function check(array $asked, mixed $accepted): array;

	/**
	 * The statements a form asks, with the portal's wording.
	 *
	 * A statement is listed when the binding names it. Its text and version
	 * come from the portal; a missing text is kept empty so the check can
	 * refuse a required statement nobody can read.
	 *
	 * @param mixed                $declared The binding's `statements`.
	 * @param array<string, mixed> $site     The portal.
	 *
	 * @return array<int, array{key: string, required: bool, text: string, version: string}>
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
	 */
	public function asked(mixed $declared, array $site): array;
}//end interface
