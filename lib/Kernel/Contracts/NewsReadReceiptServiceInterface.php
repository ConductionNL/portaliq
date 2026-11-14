<?php

/**
 * Contract of NewsReadReceiptService
 *
 * The public surface of NewsReadReceiptService that other components call, so they depend
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
 * Contract of NewsReadReceiptService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NewsReadReceiptServiceInterface {

	/**
	 * Mark one `newsItem` read by one guardian, idempotently. Returns false
	 * for EVERY failure shape (not found, not published, out-of-audience) —
	 * the controller maps false to a single 404, carrying no existence oracle.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $id The newsItem id.
	 *
	 * @return bool True on success (including "already recorded").
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 */
	public function markRead(string $subjectRef, string $id): bool;
}//end interface
