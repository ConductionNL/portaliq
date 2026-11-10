<?php

/**
 * OpenRegister test stub: a verbatim copy of openregister's FormSubmitter on
 * main (openregister #7788 / #7964), loaded only when OpenRegister is absent (tests/bootstrap.php) and
 * read by psalm and phpstan. Only the @spec tags differ.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Form
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

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Service\Object\DraftOwner;
use OCP\IUser;

/**
 * Who a submit is by and where it lands: the subject, the form scope, the draft and its owner.
 *
 * @spec exclude test stub of an OpenRegister class
 */
final readonly class FormSubmitter {

	/**
	 * Bundle the submitter.
	 *
	 * @param IUser|null      $subject The signed-in subject; null for anonymous.
	 * @param string          $scope   The form id the key and upload tokens belong to.
	 * @param string|null     $draftId The draft saved into or left, or null.
	 * @param DraftOwner|null $owner   The draft's asserted owner; null takes the subject.
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public function __construct(
		public ?IUser $subject = null,
		public string $scope = '',
		public ?string $draftId = null,
		public ?DraftOwner $owner = null,
	) {
	}//end __construct()
}//end class
