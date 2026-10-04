<?php

/**
 * Portaliq Required Fields Guard (site-multi-step-forms, REQ-SMF-023)
 *
 * The server half of an action's required fields. Every action submit that
 * writes or forwards a body asks this guard first: a create (signed in or
 * anonymous), an endpoint action, a row or attached action, and a guest
 * action. A required field left empty refuses the submit with 400 and one
 * entry per field, before anything is written, audited or forwarded, so the
 * marker in the form is never decorative.
 *
 * The required set is the one RequiredFieldsNormaliser wrote as
 * `fieldConfigs.<field>.required: true` on the normalised action, so the
 * form and this guard can never disagree. A file field is skipped: it is
 * uploaded after the record exists and is never in the body.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-the-server-must-refuse-a-submit-that-leaves-a-required-field-empty-req-smf-024
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Contribution\FileFieldConfigNormaliser;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Refuses a submit that leaves one of the action's required fields empty.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-the-server-must-refuse-a-submit-that-leaves-a-required-field-empty-req-smf-024
 */
class RequiredFieldsGuard {
	/**
	 * The error code of the refusal.
	 */
	public const ERROR = 'required_missing';

	/**
	 * The required fields the body leaves empty, each with the action's own
	 * `requiredMessage` or '' (the site then words it).
	 *
	 * @param array<string, mixed> $action The normalised action.
	 * @param array<string, mixed>|null $body The body about to be written or forwarded.
	 *
	 * @return array<string, string> Message per missing field, in `fields` order.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-the-server-must-refuse-a-submit-that-leaves-a-required-field-empty-req-smf-024
	 */
	public function missing(array $action, ?array $body): array {
		$configs = ($action['fieldConfigs'] ?? []);
		if (is_array($configs) === false) {
			return [];
		}

		$missing = [];
		foreach ((array)($action['fields'] ?? []) as $field) {
			$config = ($configs[$field] ?? null);
			if (is_string($field) === false
				|| is_array($config) === false
				|| ($config['required'] ?? false) !== true
				|| ($config['type'] ?? null) === FileFieldConfigNormaliser::TYPE_FILE
			) {
				continue;
			}

			if ($this->isEmpty(value: ($body[$field] ?? null)) === true) {
				$missing[$field] = '';
				if (is_string($config['requiredMessage'] ?? null) === true) {
					$missing[$field] = $config['requiredMessage'];
				}
			}
		}

		return $missing;
	}//end missing()

	/**
	 * The 400 answer for a body that leaves a required field empty, or null
	 * when every required field is filled.
	 *
	 * @param array<string, mixed> $action The normalised action.
	 * @param array<string, mixed>|null $body The body about to be written or forwarded.
	 *
	 * @return JSONResponse|null The refusal, or null to go on.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-the-server-must-refuse-a-submit-that-leaves-a-required-field-empty-req-smf-024
	 */
	public function refusal(array $action, ?array $body): ?JSONResponse {
		$missing = $this->missing(action: $action, body: $body);
		if ($missing === []) {
			return null;
		}

		return new JSONResponse(['error' => self::ERROR, 'errors' => $missing], Http::STATUS_BAD_REQUEST);
	}//end refusal()

	/**
	 * The refusal of an endpoint forward: 403 when the scoped body did not
	 * resolve (a declared scope claim without a value), else 400 when a
	 * declared `fields` body leaves a required field empty, else null. An
	 * action without `fields` relays the raw body and has no required fields.
	 *
	 * @param array<string, mixed> $action The authorised endpoint action.
	 * @param array{body: array<string, mixed>|null, scopeValue: string}|null $scoped The prepared body, or null.
	 * @param bool $declaresFields Whether the action declares `fields`.
	 *
	 * @return JSONResponse|null The refusal, or null to forward.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-the-server-must-refuse-a-submit-that-leaves-a-required-field-empty-req-smf-024
	 */
	public function forwardRefusal(array $action, ?array $scoped, bool $declaresFields): ?JSONResponse {
		if ($scoped === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($declaresFields === false) {
			return null;
		}

		return $this->refusal(action: $action, body: $scoped['body']);
	}//end forwardRefusal()

	/**
	 * Whether a value counts as not filled in: absent, null, blank text or an
	 * empty list. `0` and `false` are answers.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when empty.
	 */
	private function isEmpty(mixed $value): bool {
		if ($value === null || $value === []) {
			return true;
		}

		return (is_string($value) === true && trim($value) === '');
	}//end isEmpty()
}//end class
