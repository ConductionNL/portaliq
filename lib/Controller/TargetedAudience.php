<?php

/**
 * Portaliq Targeted Audience
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
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;


/**
 * Whether an authored item names at least one audience: a school, groups or children.
 *
 * Used by controllers that author targeted items.
 *
 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */
trait TargetedAudience {
	/**
	 * Whether a target names at least one dimension.
	 *
	 * @param array<string, mixed> $target The target.
	 *
	 * @return bool
	 */
	private function hasAnyTarget(array $target): bool {
		if (is_string($target['schoolRef'] ?? null) === true && $target['schoolRef'] !== '') {
			return true;
		}

		if (is_array($target['groupRefs'] ?? null) === true && count($target['groupRefs']) > 0) {
			return true;
		}

		if (is_array($target['childRefs'] ?? null) === true && count($target['childRefs']) > 0) {
			return true;
		}

		return false;
	}//end hasAnyTarget()
}//end trait
