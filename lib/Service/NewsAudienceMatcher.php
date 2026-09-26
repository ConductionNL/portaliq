<?php

/**
 * News Audience Matcher
 *
 * Pure predicate: does a newsItem/newsletter `target` (schoolRef and/or
 * groupRefs and/or childRefs) intersect a guardian's resolved audience?
 * ANY match includes the item — the same "school OR group OR child" rule
 * `news-and-newsletter-authoring`'s spec names. Stateless and side-effect
 * free on purpose: `NewsFeedReader`, `NewsletterPreflightService` and
 * `GuardianAudienceFixtureReader::guardiansMatching()` all call this SAME
 * method, so the preflight count can never drift from the actual delivery
 * set.
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
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#nextcloud-integration
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#nextcloud-integration
 */
class NewsAudienceMatcher {
	/**
	 * Whether a target intersects an audience — school OR group OR child,
	 * any single match is enough. A target with no populated dimension at all
	 * matches NOBODY (fail-closed: an item with a malformed/empty target never
	 * reaches everyone by accident).
	 *
	 * @param array{schoolRef?: mixed, groupRefs?: mixed, childRefs?: mixed} $target The item's target.
	 * @param array{schoolRef?: mixed, groupRefs?: mixed, childRefs?: mixed} $audience The guardian's resolved audience.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/design.md#nextcloud-integration
	 */
	public static function matches(array $target, array $audience): bool {
		$targetSchool = (string)($target['schoolRef'] ?? '');
		if ($targetSchool !== '' && $targetSchool === (string)($audience['schoolRef'] ?? '')) {
			return true;
		}

		if (self::intersects(a: $target['groupRefs'] ?? [], b: $audience['groupRefs'] ?? []) === true) {
			return true;
		}

		if (self::intersects(a: $target['childRefs'] ?? [], b: $audience['childRefs'] ?? []) === true) {
			return true;
		}

		return false;
	}//end matches()

	/**
	 * Whether two lists share at least one element (strict string equality).
	 *
	 * @param mixed $a The first list.
	 * @param mixed $b The second list.
	 *
	 * @return bool
	 */
	private static function intersects(mixed $a, mixed $b): bool {
		if (is_array($a) === false || is_array($b) === false) {
			return false;
		}

		foreach ($a as $item) {
			if (is_string($item) === true && $item !== '' && in_array($item, $b, true) === true) {
				return true;
			}
		}

		return false;
	}//end intersects()
}//end class
