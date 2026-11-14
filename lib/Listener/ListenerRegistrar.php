<?php

/**
 * Event listener registrations of the Listener component
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
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
 * @spec exclude Structural composition root, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Event\LandingPageRequestedEvent;
use OCA\Portaliq\Event\PortalAccountClaimRequestedEvent;
use OCA\Portaliq\Event\PortalAccountInvitationRequestedEvent;
use OCA\Portaliq\Event\PortalAccountProvisionRequestedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;

/**
 * Event listener registrations of the Listener component.
 *
 * Application::register() hands the registration context to this class, so
 * the Listener component states its own event bindings and AppInfo does not
 * name the listeners. The listeners, their events and their order are
 * unchanged.
 *
 * @spec exclude Structural composition root, no functional requirement.
 */
final class ListenerRegistrar {

	/**
	 * Register the app's event listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Structural composition root, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerCmsListeners(context: $context);
		$this->registerRecordChangeListener(context: $context);

		// Landing-page-provisioning (ADR-041, contribution-landing-page-action):
		// a same-instance cross-app command letting a contributing app ask
		// Portaliq to provision a draft landing page + form, and the fail-safe
		// relay of a visitor's submission back to that app.
		$context->registerEventListener(LandingPageRequestedEvent::class, LandingPageRequestedEventListener::class);
		$context->registerEventListener(ObjectCreatedEvent::class, LandingPageSubmissionDispatchListener::class);

		// The identity space (portal-identity-space, ADR-041): an app asks for
		// a portal account before its owner has ever logged in, and links its
		// own record to that account. Both answer in the event's result slot,
		// because another app never calls into portaliq's controllers.
		$context->registerEventListener(PortalAccountProvisionRequestedEvent::class, PortalAccountProvisionListener::class);
		$context->registerEventListener(PortalAccountClaimRequestedEvent::class, PortalAccountClaimListener::class);
		$context->registerEventListener(PortalAccountInvitationRequestedEvent::class, PortalAccountInvitationListener::class);
		$this->registerResidentLanding(context: $context);
	}//end register()

	/**
	 * A member of a portal's resident group starts on portaliq, so the
	 * registration app's last step lands on the portal, not on the workplace
	 * (resident-lands-on-the-portal, REQ-NCL-007).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/resident-lands-on-the-portal/specs/portal-nextcloud-account-login/spec.md#requirement-a-residents-nextcloud-start-page-is-the-portal-req-ncl-007
	 */
	private function registerResidentLanding(IRegistrationContext $context): void {
		$context->registerEventListener(UserAddedEvent::class, ResidentLandingListener::class);
		$context->registerEventListener(UserRemovedEvent::class, ResidentLandingListener::class);
	}//end registerResidentLanding()

	/**
	 * The CMS content listeners.
	 *
	 * Drop cached public content when a CMS object is written (ADR-086 §9).
	 * The read cache holds NEGATIVE results too, so without this a route keeps
	 * 404ing for the rest of the TTL after its page is created: the editor
	 * sees a broken site and is right.
	 *
	 * The media library's write rules (site-page-seo-history-and-media T06,
	 * T09): an image needs alternative text, and an item a published page uses
	 * is not deleted. OpenRegister honours a stopped pre-write event by
	 * refusing the write with its message.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	private function registerCmsListeners(IRegistrationContext $context): void {
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			$context->registerEventListener($event, CmsCacheInvalidationListener::class);
		}

		// A move out of the register takes the row away like a delete.
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class, ObjectDeletingEvent::class, ObjectLeavingEvent::class] as $event) {
			$context->registerEventListener($event, MediaWriteGuardListener::class);
		}

		// A portal's logo, favicon and hero image: its own media only, and a
		// favicon that is a PNG, SVG or ICO (portal-identity-from-the-admin).
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener($event, PortalImagesGuardListener::class);
		}

		// A glossary term relates only to terms of its own portal (portal-cms-content-model).
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener($event, GlossaryRelationGuardListener::class);
		}

		// A published form binding fits its destination (submit-creates-the-case-directly REQ-PIFO-007).
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener($event, BindingDestinationGuardListener::class);
		}

		// A notice ends after it starts (operate-maintenance-notice REQ-OMN-003).
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener($event, NoticeWriteGuardListener::class);
		}
	}//end registerCmsListeners()

	/**
	 * Tell a resident when a record they follow changes, or when a case app
	 * writes to their inbox (inbox-notifications-and-preferences). The
	 * listener's first check is a lookup built once per request, and it never
	 * fails the save it listens to.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
	 */
	private function registerRecordChangeListener(IRegistrationContext $context): void {
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class] as $event) {
			$context->registerEventListener($event, PortalRecordChangeListener::class);
		}

		// Integriq's report on a message box letter (inbox-berichtenbox-channel).
		// Named by string: without integriq nothing dispatches it.
		$context->registerEventListener(PortalDigitalPostDeliveredListener::EVENT, PortalDigitalPostDeliveredListener::class);

		// The change-proposal queue as two OpenRegister leaves
		// (change-proposal-queue). Named by string: without OpenRegister
		// nothing collects leaves.
		$context->registerEventListener(RegisterProposalLeavesListener::EVENT, RegisterProposalLeavesListener::class);
	}//end registerRecordChangeListener()
}//end class
