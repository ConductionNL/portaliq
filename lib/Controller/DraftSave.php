<?php

/**
 * Portaliq Draft Save
 *
 * One save of a resident's draft: the matched action, whose draft it is, the
 * body to store, and the scope it is kept under.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * The values one PortalDraftController::save() call works with.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */
final class DraftSave {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $match      The matched action, as ActionDraft::actionFor() answers it.
	 * @param string               $subjectRef The resident whose draft it is.
	 * @param array<string, mixed> $body       The body to store.
	 * @param string               $scope      The `<appId>/<actionId>` the draft is kept under.
	 */
	public function __construct(
		public readonly array $match,
		public readonly string $subjectRef,
		public readonly array $body,
		public readonly string $scope,
	) {
	}//end __construct()
}//end class
