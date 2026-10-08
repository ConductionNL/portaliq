<?php

/**
 * Portaliq PortalAccountProvisionRequestedEvent
 *
 * The typed request (ADR-041) an app dispatches to have a portal account
 * provisioned before its owner has ever logged in. The answer comes back in
 * the event's result slot: portaliq never lets another app call into its
 * controllers, and the caller needs the `subjectRef` to attach a case to.
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
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * An app asks portaliq to provision a pending portal account.
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class PortalAccountProvisionRequestedEvent extends Event {
	/**
	 * The subject reference of the account, once portaliq has answered.
	 *
	 * @var string
	 */
	private string $subjectRef = '';

	/**
	 * The account's status, once portaliq has answered.
	 *
	 * @var string
	 */
	private string $status = '';

	/**
	 * Why the request was refused, or '' when it was not.
	 *
	 * @var string
	 */
	private string $refusal = '';

	/**
	 * Constructor.
	 *
	 * @param string $appId The dispatching app, taken from its own context.
	 * @param string $audience The external audience the account belongs to.
	 * @param string $organisation The tenant slug.
	 * @param string $identityType One of the register's identityType enum, or ''.
	 * @param string $identityRef The identity reference, or ''.
	 * @param string $email A contact address, or ''.
	 * @param bool $verifiedEmail True when that address was verified out of band.
	 * @param string $displayName The name to greet the person by, or ''.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one parameter per
	 * declared field of the account being asked for.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- `verifiedEmail` is one
	 * of those declared fields. It becomes a readonly property the listener
	 * reads back; this constructor branches on nothing, so there is no second
	 * responsibility to split off.
	 */
	public function __construct(
		private readonly string $appId,
		private readonly string $audience,
		private readonly string $organisation,
		private readonly string $identityType = '',
		private readonly string $identityRef = '',
		private readonly string $email = '',
		private readonly bool $verifiedEmail = false,
		private readonly string $displayName = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The dispatching app.
	 *
	 * @return string
	 */
	public function getAppId(): string {
		return $this->appId;
	}//end getAppId()

	/**
	 * The external audience the account belongs to.
	 *
	 * @return string
	 */
	public function getAudience(): string {
		return $this->audience;
	}//end getAudience()

	/**
	 * The tenant slug.
	 *
	 * @return string
	 */
	public function getOrganisation(): string {
		return $this->organisation;
	}//end getOrganisation()

	/**
	 * The identity type, or ''.
	 *
	 * @return string
	 */
	public function getIdentityType(): string {
		return $this->identityType;
	}//end getIdentityType()

	/**
	 * The identity reference, or ''.
	 *
	 * @return string
	 */
	public function getIdentityRef(): string {
		return $this->identityRef;
	}//end getIdentityRef()

	/**
	 * The contact address, or ''.
	 *
	 * @return string
	 */
	public function getEmail(): string {
		return $this->email;
	}//end getEmail()

	/**
	 * Whether the address was verified out of band.
	 *
	 * @return bool
	 */
	public function isVerifiedEmail(): bool {
		return $this->verifiedEmail;
	}//end isVerifiedEmail()

	/**
	 * The name to greet the person by, or ''.
	 *
	 * @return string
	 */
	public function getDisplayName(): string {
		return $this->displayName;
	}//end getDisplayName()

	/**
	 * Portaliq's answer: the account it provisioned or found.
	 *
	 * @param string $subjectRef The account's subject reference.
	 * @param string $status The account's lifecycle status.
	 *
	 * @return void
	 */
	public function answer(string $subjectRef, string $status): void {
		$this->subjectRef = $subjectRef;
		$this->status = $status;
		$this->refusal = '';
	}//end answer()

	/**
	 * Portaliq's answer when it refused.
	 *
	 * @param string $reason The refusal, in a machine-readable word.
	 *
	 * @return void
	 */
	public function refuse(string $reason): void {
		$this->subjectRef = '';
		$this->status = '';
		$this->refusal = $reason;
	}//end refuse()

	/**
	 * The provisioned account's subject reference, or '' when refused.
	 *
	 * @return string
	 */
	public function getSubjectRef(): string {
		return $this->subjectRef;
	}//end getSubjectRef()

	/**
	 * The provisioned account's status, or '' when refused.
	 *
	 * @return string
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/**
	 * The refusal, or '' when the request was answered.
	 *
	 * @return string
	 */
	public function getRefusal(): string {
		return $this->refusal;
	}//end getRefusal()
}//end class
