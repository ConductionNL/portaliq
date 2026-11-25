<?php

/**
 * OpenRegister test stub: a copy of openregister's class on `main`, loaded
 * only when OpenRegister is absent (tests/bootstrap-unit.php). Only the @spec
 * tag differs: it points at portaliq's change.
 *
 * Thrown when an app's hook on an object event stops the write.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-owning-apps-refusal-reads-as-no-longer-possible/specs/site-forms/spec.md#requirement-a-refusal-by-the-records-own-app-must-read-as-no-longer-possible-req-orr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * An app's hook stopped the write.
 */
class HookStoppedException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string               $message The refusal.
	 * @param array<string, mixed> $errors  The hook's errors.
	 */
	public function __construct(
		string $message='Operation blocked by schema hook',
		private readonly array $errors=[],
	) {
		parent::__construct(message: $message, code: 0);
	}//end __construct()

	/**
	 * The hook's errors.
	 *
	 * @return array<string, mixed>
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
