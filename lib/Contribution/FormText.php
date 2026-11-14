<?php

/**
 * Portaliq Form Text (site-multi-step-forms, REQ-SMF-020, -022)
 *
 * The plain text a form declaration may carry: a step's title or
 * description, or a confirmation's wording.
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
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-run-in-steps-with-a-review-a-draft-and-a-confirmation-req-smf-020
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a declared text when it is text and not too long.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-word-its-own-confirmation-req-smf-022
 */
final class FormText {
	/**
	 * The longest title, description or confirmation text kept.
	 */
	private const MAX_TEXT = 2000;

	/**
	 * A trimmed text, or '' when it is not text or too long.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-create-or-endpoint-action-may-word-its-own-confirmation-req-smf-022
	 */
	public function clean(mixed $value): string {
		if (is_string($value) === false || mb_strlen($value) > self::MAX_TEXT) {
			return '';
		}

		return trim($value);
	}//end clean()
}//end class
