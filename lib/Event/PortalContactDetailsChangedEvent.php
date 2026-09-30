<?php

/**
 * Portaliq PortalContactDetailsChangedEvent
 *
 * A person chose another way for the organisation to contact them: through the
 * portal only, by e-mail, by phone or by post (identity-profile-page, D3).
 * Portaliq sends no letter and makes no call; a case app that does listens for
 * this event and honours the choice. The addresses themselves are not in the
 * event: it says only whether a preferred e-mail address and phone number
 * exist, and the case app reads its own contact record.
 *
 * @category Event
 * @package  OCA\Portaliq\Event
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
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-choose-how-the-organisation-contacts-you-req-ipp-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * A changed contact channel.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-choose-how-the-organisation-contacts-you-req-ipp-004
 */
class PortalContactDetailsChangedEvent extends Event {
	/**
	 * The canonical name of this fact.
	 */
	public const NAME = 'portal.contact-details.changed';

	/**
	 * Constructor.
	 *
	 * @param string $subjectRef The person, as the apps scope by it.
	 * @param string $organisation The organisation the account belongs to.
	 * @param string $channel `portal`, `email`, `phone` or `post`.
	 * @param array{email: bool, phone: bool} $preferred Whether a preferred e-mail address and phone number exist.
	 */
	public function __construct(
		private readonly string $subjectRef,
		private readonly string $organisation,
		private readonly string $channel,
		private readonly array $preferred,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The person, as the apps scope by it.
	 *
	 * @return string
	 */
	public function getSubjectRef(): string {
		return $this->subjectRef;
	}//end getSubjectRef()

	/**
	 * The organisation the account belongs to.
	 *
	 * @return string
	 */
	public function getOrganisation(): string {
		return $this->organisation;
	}//end getOrganisation()

	/**
	 * The chosen channel.
	 *
	 * @return string
	 */
	public function getChannel(): string {
		return $this->channel;
	}//end getChannel()

	/**
	 * Whether the account has a preferred, confirmed e-mail address.
	 *
	 * @return bool
	 */
	public function hasPreferredEmail(): bool {
		return ($this->preferred['email'] ?? false) === true;
	}//end hasPreferredEmail()

	/**
	 * Whether the account has a preferred phone number.
	 *
	 * @return bool
	 */
	public function hasPreferredPhone(): bool {
		return ($this->preferred['phone'] ?? false) === true;
	}//end hasPreferredPhone()
}//end class
