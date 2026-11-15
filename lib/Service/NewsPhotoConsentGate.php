<?php

/**
 * News Photo Consent Gate
 *
 * Withholds a `newsItem`'s `photoRefs` from a read when ANY targeted child's
 * photo consent is not granted — the item's title/body still deliver.
 * Strengthens the nearest documented competitor behaviour, Parnassys's
 * per-child privacy preference "reviewed... by the teacher" (findings 9.5),
 * from a UI checklist into a server-enforced gate: this runs on every read,
 * regardless of who authored or is viewing the item.
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-photos-in-a-news-item-are-gated-by-the-target-childs-photo-consent
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-photos-in-a-news-item-are-gated-by-the-target-childs-photo-consent
 */
class NewsPhotoConsentGate {
	/**
	 * Constructor.
	 *
	 * @param GuardianAudienceFixtureReader $audienceReader Per-child consent lookup.
	 */
	public function __construct(
		private readonly GuardianAudienceFixtureReader $audienceReader,
	) {
	}//end __construct()

	/**
	 * Redact a `newsItem` row's `photoRefs` in place when any targeted child's
	 * consent is not granted. A row with no `photoRefs`, or a target with no
	 * `childRefs` at all (school- or group-only targeting names no specific
	 * child to withhold for), passes through unchanged.
	 *
	 * @param array<string, mixed> $item The newsItem row.
	 *
	 * @return array<string, mixed> The row, `photoRefs` withheld when required.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-photos-in-a-news-item-are-gated-by-the-target-childs-photo-consent
	 */
	public function apply(array $item): array {
		$photoRefs = $item['photoRefs'] ?? [];
		if (is_array($photoRefs) === false || count($photoRefs) === 0) {
			return $item;
		}

		$childRefs = $item['target']['childRefs'] ?? [];
		if (is_array($childRefs) === false || count($childRefs) === 0) {
			// No specific child named — nothing to withhold for.
			return $item;
		}

		if ($this->anyConsentWithheld(childRefs: $childRefs) === true) {
			$item['photoRefs'] = [];
		}

		return $item;
	}//end apply()

	/**
	 * Whether any named child's photo consent for news is not granted,
	 * asking in order and stopping at the first that withholds. An empty or
	 * non-string ref names no child.
	 *
	 * @param array<int|string, mixed> $childRefs The targeted children.
	 *
	 * @return bool
	 */
	private function anyConsentWithheld(array $childRefs): bool {
		foreach ($childRefs as $childRef) {
			if (is_string($childRef) === false || $childRef === '') {
				continue;
			}

			if ($this->audienceReader->childPhotoConsentGranted(childRef: $childRef, purpose: GuardianAudienceFixtureReader::PURPOSE_NEWS) === false) {
				return true;
			}
		}

		return false;
	}//end anyConsentWithheld()
}//end class
