<?php
/**
 * Portaliq Account Page Choice (operate-pages-per-portal-and-client)
 *
 * The pages staff hid on one client's account (`portalAccount.hiddenPages`),
 * applied to that account's aggregate. The account is read once per
 * subjectRef per request, since one request can aggregate several times, and
 * not at all for a subject without a subjectRef.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;

/**
 * Applies one client account's hidden pages.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */
class PortalAccountPageChoice {

	/**
	 * Hidden pages per subjectRef, for this request.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $hiddenPages = [];

	/**
	 * Constructor.
	 *
	 * @param PortalAccountLookup $accounts Reads the account of a subjectRef.
	 * @param PortalPageChoice $choice Drops the pages and the collections only they showed.
	 */
	public function __construct(
		private readonly PortalAccountLookup $accounts,
		private readonly PortalPageChoice $choice = new PortalPageChoice(),
	) {
	}//end __construct()

	/**
	 * The contributions without the pages hidden on this subject's account.
	 *
	 * @param array<int, array<string, mixed>> $contributions The aggregate's contributions.
	 * @param string $subjectRef The subject's reference; '' reads nothing.
	 *
	 * @return array<int, array<string, mixed>> The contributions.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function apply(array $contributions, string $subjectRef): array {
		if ($subjectRef === '') {
			return $contributions;
		}

		if (array_key_exists($subjectRef, $this->hiddenPages) === false) {
			$hidden = ($this->accounts->bySubjectRef(subjectRef: $subjectRef)['hiddenPages'] ?? []);
			if (is_array($hidden) === false) {
				$hidden = [];
			}

			$this->hiddenPages[$subjectRef] = array_values(array_filter($hidden, 'is_string'));
		}

		return $this->choice->hideForAccount(contributions: $contributions, hiddenPages: $this->hiddenPages[$subjectRef]);
	}//end apply()
}//end class
