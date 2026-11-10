<?php

/**
 * OpenRegister test stub: a verbatim copy of openregister's DiscardDraftRequest on
 * main (openregister #7788 / #7964), loaded only when OpenRegister is absent (tests/bootstrap.php) and
 * read by psalm and phpstan. Only the @spec tags differ.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude test stub of an OpenRegister class
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * One request to discard an owner's draft: the destination it lives in, its id, and who asks.
 *
 * @spec exclude test stub of an OpenRegister class
 */
final class DiscardDraftRequest {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $destination The destination pair (id, uuid, slug or entity).
	 * @param string               $draftId     The draft's uuid.
	 * @param DraftOwner           $owner       The asserted owner.
	 */
	public function __construct(
		public readonly array $destination,
		public readonly string $draftId,
		public readonly DraftOwner $owner,
	) {
	}//end __construct()
}//end class
