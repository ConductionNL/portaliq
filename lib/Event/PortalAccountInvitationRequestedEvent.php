<?php

/**
 * Portaliq PortalAccountInvitationRequestedEvent
 *
 * The typed request (ADR-041) an app dispatches to have Portaliq invite the
 * person behind a waiting account it provisioned. Portaliq mints a one-time
 * secret and mails it to the account's address, inside a link. The secret
 * never comes back in this event: the app, and the staff member using it,
 * never see it.
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
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * An app asks portaliq to mail the invitation of a waiting account.
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class PortalAccountInvitationRequestedEvent extends Event {
	/**
	 * The invitation was mailed.
	 */
	public const SENT = 'sent';

	/**
	 * The invitation exists but its mail did not leave. Asking again mails a new one.
	 */
	public const NOT_SENT = 'not_sent';

	/**
	 * Nothing was issued: not this app's waiting account, or portaliq could not write.
	 */
	public const REFUSED = 'refused';

	/**
	 * The result slot: SENT, NOT_SENT or REFUSED, '' while nothing has answered.
	 *
	 * @var string
	 */
	private string $result = '';

	/**
	 * When the invitation stops working, as an ISO 8601 date-time, or ''.
	 *
	 * @var string
	 */
	private string $expiresAt = '';

	/**
	 * Constructor.
	 *
	 * @param string $appId The dispatching app, taken from its own context.
	 * @param string $subjectRef The waiting account to invite.
	 */
	public function __construct(
		private readonly string $appId,
		private readonly string $subjectRef,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The dispatching app.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function getAppId(): string {
		return $this->appId;
	}//end getAppId()

	/**
	 * The waiting account to invite.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function getSubjectRef(): string {
		return $this->subjectRef;
	}//end getSubjectRef()

	/**
	 * Portaliq's answer.
	 *
	 * @param string $result SENT, NOT_SENT or REFUSED.
	 * @param string $expiresAt When the invitation stops working, or ''.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function answer(string $result, string $expiresAt = ''): void {
		$this->result    = $result;
		$this->expiresAt = $expiresAt;
	}//end answer()

	/**
	 * The result slot, '' while nothing has answered.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function getResult(): string {
		return $this->result;
	}//end getResult()

	/**
	 * When the invitation stops working, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function getExpiresAt(): string {
		return $this->expiresAt;
	}//end getExpiresAt()
}//end class
