<?php

/**
 * Newsletter Preflight Service
 *
 * Computes the EXACT recipient count for a newsletter's `target`, using the
 * identical audience resolution the send path uses — so the previewed count
 * can never drift from the delivered one. The Gibbon Messenger pattern
 * (`gibbon/round1/pages/Messenger.md`: "a live recipient-count preflight
 * before send") applied against the RosarioSIS anti-pattern
 * (`rosariosis/round1/journeys.md` J6: no group/class column, so the only
 * available target "over-notifies every family").
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
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
 */
class NewsletterPreflightService {
	/**
	 * Constructor.
	 *
	 * @param GuardianAudienceFixtureReader $audienceReader The audience source.
	 */
	public function __construct(
		private readonly GuardianAudienceFixtureReader $audienceReader,
	) {
	}//end __construct()

	/**
	 * The distinct guardian subjectRefs a target resolves to — the SAME
	 * method `countRecipients()` and the actual send both call, so the two
	 * can never disagree.
	 *
	 * @param array{schoolRef?: string, groupRefs?: array<int, string>, childRefs?: array<int, string>} $target The target.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
	 */
	public function recipients(array $target): array {
		return $this->audienceReader->guardiansMatching(target: $target);
	}//end recipients()

	/**
	 * The exact recipient count for a target.
	 *
	 * @param array{schoolRef?: string, groupRefs?: array<int, string>, childRefs?: array<int, string>} $target The target.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
	 */
	public function countRecipients(array $target): int {
		return count($this->recipients(target: $target));
	}//end countRecipients()

	/**
	 * Whether sending to this target should be REFUSED — true when the
	 * resolved audience is empty. Staff must broaden the target or cancel; a
	 * newsletter is never sent to nobody by accident.
	 *
	 * @param array{schoolRef?: string, groupRefs?: array<int, string>, childRefs?: array<int, string>} $target The target.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/news-and-newsletter-authoring/specs/portaliq-cms/spec.md#requirement-a-newsletter-send-is-preceded-by-a-recipient-count-preflight
	 */
	public function sendIsRefused(array $target): bool {
		return $this->countRecipients(target: $target) === 0;
	}//end sendIsRefused()
}//end class
