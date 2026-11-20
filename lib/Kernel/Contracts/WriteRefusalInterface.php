<?php

/**
 * Contract of WriteRefusal
 *
 * The public surface of WriteRefusal that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of WriteRefusal.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WriteRefusalInterface {



	/**
	 * The 422 that names the refused fields, or null when the refusal names
	 * none of the written fields.
	 *
	 * @param string               $failure The store's refusal (PortalObjectWriter::lastFailure()).
	 * @param array<string, mixed> $data    The body the portal wrote.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
	 */
	public function response(string $failure, array $data): ?JSONResponse;
}//end interface
