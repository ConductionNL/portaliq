<?php

/**
 * Portaliq Hidden Pages Reader (operate-pages-per-portal-and-client)
 *
 * Reads the pages a clerk hid for a subject's account.
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
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The pages hidden for a subject's account, read once per request.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */
class HiddenPagesReader {
	/**
	 * The hidden pages of each account read so far, by subject reference.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $hiddenByRef = [];

	/**
	 * Constructor.
	 *
	 * @param PortalAccountLookup|null $accounts Reads the account's hidden pages; none hides nothing.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ?PortalAccountLookup $accounts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The pages hidden for this subject's account, read once per request.
	 * An account that cannot be read hides nothing.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<int, string> `<app>:<pageId>` entries.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function forSubject(array $subject): array {
		$ref = (string)($subject['subjectRef'] ?? '');
		if ($this->accounts === null || $ref === '') {
			return [];
		}

		if (array_key_exists($ref, $this->hiddenByRef) === false) {
			$hidden = [];
			try {
				$account = $this->accounts->bySubjectRef(subjectRef: $ref);
				$hidden  = (array)($account['hiddenPages'] ?? []);
			} catch (Throwable $e) {
				$this->logger->warning('Portaliq: hidden pages not read', ['reason' => $e->getMessage()]);
			}

			$this->hiddenByRef[$ref] = array_values(array_filter($hidden, 'is_string'));
		}

		return $this->hiddenByRef[$ref];
	}//end forSubject()
}//end class
