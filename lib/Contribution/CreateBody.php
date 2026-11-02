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

use DateTimeImmutable;
use DateTimeZone;

/**
 * Applies an action's declared defaults over its whitelisted body.
 *
 * @spec openspec/changes/claim-scoped-create-stamps-the-claim/tasks.md#T1
 */
class CreateBody {

	/**
	 * The action key naming the field the server stamps with the moment of the create.
	 *
	 * @var string
	 */
	public const CREATED_AT_FIELD = 'createdAtField';

	/**
	 * A field name the stamp may write: a plain property name, never a path.
	 *
	 * @var string
	 */
	private const FIELD_NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

	/**
	 * The whitelisted body with the action's defaults stamped over it.
	 *
	 * The field the action names in `createdAtField` is stamped with the
	 * moment of the create, over anything the client sent.
	 *
	 * @param array<string, mixed>   $action      The create action.
	 * @param array<string, mixed>   $whitelisted The whitelisted client body.
	 * @param DateTimeImmutable|null $now         The moment; now when null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/claim-scoped-create-stamps-the-claim/tasks.md#T1
	 * @spec openspec/changes/a-create-stamps-the-moment-it-was-made/specs/portal-contribution-contract/spec.md#requirement-a-create-action-may-name-the-field-that-holds-when-it-was-made
	 */
	public function build(array $action, array $whitelisted, ?DateTimeImmutable $now = null): array {
		foreach ((array)($action['defaults'] ?? []) as $key => $value) {
			if (is_string($key) === true && $key !== '') {
				$whitelisted[$key] = $value;
			}
		}

		$field = ($action[self::CREATED_AT_FIELD] ?? null);
		if (is_string($field) === true && preg_match(self::FIELD_NAME, $field) === 1) {
			$moment = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
			$whitelisted[$field] = $moment->format(DATE_ATOM);
		}

		return $whitelisted;
	}//end build()
}//end class
