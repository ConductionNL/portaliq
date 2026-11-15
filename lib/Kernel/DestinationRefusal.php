<?php

/**
 * The answer to a submit that did not create the destination object
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-submission-is-validated-against-its-destinations-schema-before-anything-is-stored-req-pifo-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel;

/**
 * The refusal answer of a destination submit, readable from every component.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-submission-is-validated-against-its-destinations-schema-before-anything-is-stored-req-pifo-004
 */
final class DestinationRefusal {

	/**
	 * The answer to a submit that did not create the destination object.
	 *
	 * 422: the destination's message on each refused field; nothing was
	 * stored. 403/404: the form cannot be sent. Anything else, and no submit
	 * at all: the request did not arrive, the page keeps the answers and says
	 * to try again later. Nothing is queued (decision 181).
	 *
	 * @param array<string, mixed>|null $submitted What submit() answered, or null when there was no intake.
	 *
	 * @return array{body: array<string, mixed>, status: int}
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-submission-is-validated-against-its-destinations-schema-before-anything-is-stored-req-pifo-004
	 */
	public static function of(?array $submitted): array {
		$status = (int)($submitted['status'] ?? 503);
		if ($status === 422) {
			return ['body' => ['error' => 'refused_by_destination', 'errors' => (array)($submitted['errors'] ?? [])], 'status' => 422];
		}

		if ($status === 403 || $status === 404) {
			return ['body' => ['error' => 'form_not_submittable'], 'status' => $status];
		}

		return ['body' => ['error' => 'try_again_later'], 'status' => 503];
	}//end of()
}//end class
