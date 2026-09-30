<?php

/**
 * Create Body
 *
 * The body a portal create writes: the action's whitelisted fields, then the
 * action's declared `defaults` stamped over them, so a client can never
 * override a default (for example pipelinq's `ticketType` supertype
 * discriminator, required by the schema but never client-edited). Shared by
 * the authenticated and the anonymous create path.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/claim-scoped-create-stamps-the-claim/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Applies an action's declared defaults over its whitelisted body.
 *
 * @spec openspec/changes/claim-scoped-create-stamps-the-claim/tasks.md#T1
 */
class CreateBody {

	/**
	 * The whitelisted body with the action's defaults stamped over it.
	 *
	 * @param array<string, mixed> $action The create action.
	 * @param array<string, mixed> $whitelisted The whitelisted client body.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/claim-scoped-create-stamps-the-claim/tasks.md#T1
	 */
	public function build(array $action, array $whitelisted): array {
		foreach ((array)($action['defaults'] ?? []) as $key => $value) {
			if (is_string($key) === true && $key !== '') {
				$whitelisted[$key] = $value;
			}
		}

		return $whitelisted;
	}//end build()
}//end class
