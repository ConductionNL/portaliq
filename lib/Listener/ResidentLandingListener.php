<?php

/**
 * Portaliq ResidentLandingListener
 *
 * A resident who makes an account with Nextcloud's registration app is
 * signed in by that app and sent to Nextcloud's default page, which is the
 * workplace (`linkToDefaultPageUrl()`). Nextcloud reads the user's own
 * `core`/`defaultapp` preference before the system `defaultapp`, so when a
 * user joins a group a published portal names as its resident group or its
 * business group, this listener makes portaliq that user's start page. Portaliq's app page then
 * sends a resident on to their portal (REQ-NCL-006). A preference the user
 * already has is left alone, an administrator is never touched, and the
 * preference is taken back when the user leaves the last such group, if
 * it is still portaliq's.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/resident-lands-on-the-portal/specs/portal-nextcloud-account-login/spec.md#requirement-a-residents-nextcloud-start-page-is-the-portal-req-ncl-007
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Identity\NextcloudRegistrationDoor;
use OCA\Portaliq\Service\PortalResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes portaliq the start page of a member of a portal's resident group.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/resident-lands-on-the-portal/specs/portal-nextcloud-account-login/spec.md#requirement-a-residents-nextcloud-start-page-is-the-portal-req-ncl-007
 */
class ResidentLandingListener implements IEventListener {

	/**
	 * The app whose preference Nextcloud reads for the start page.
	 */
	private const CORE = 'core';

	/**
	 * The preference Nextcloud reads before the system `defaultapp`.
	 */
	private const DEFAULT_APP = 'defaultapp';

	/**
	 * Constructor.
	 *
	 * @param IConfig                   $config    The user's preferences.
	 * @param IGroupManager             $groups    Whether the user is an administrator.
	 * @param PortalResolver            $portals   The published portals.
	 * @param NextcloudRegistrationDoor $residents Which group a portal names, and who is a resident.
	 * @param LoggerInterface           $logger    Records a failure, which never stops the membership.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IGroupManager $groups,
		private readonly PortalResolver $portals,
		private readonly NextcloudRegistrationDoor $residents,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a membership change.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/resident-lands-on-the-portal/specs/portal-nextcloud-account-login/spec.md#requirement-a-residents-nextcloud-start-page-is-the-portal-req-ncl-007
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof UserAddedEvent) {
				$this->joined(gid: $event->getGroup()->getGID(), uid: $event->getUser()->getUID());
				return;
			}

			if ($event instanceof UserRemovedEvent) {
				$this->left(gid: $event->getGroup()->getGID(), uid: $event->getUser()->getUID());
			}
		} catch (Throwable $e) {
			// A start page is a convenience. A registration or a group change
			// must never fail because of it.
			$this->logger->warning(
				'Portaliq: the start page of a resident was not changed',
				['app' => Application::APP_ID, 'reason' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * A user joined a group: make portaliq their start page when a published
	 * portal names the group, unless they are an administrator or already
	 * chose a start page.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function joined(string $gid, string $uid): void {
		if ($this->namedByAPortal(gid: $gid) === false || $this->groups->isAdmin($uid) === true) {
			return;
		}

		if ($this->config->getUserValue($uid, self::CORE, self::DEFAULT_APP, '') !== '') {
			return;
		}

		$this->config->setUserValue($uid, self::CORE, self::DEFAULT_APP, Application::APP_ID);
	}//end joined()

	/**
	 * A user left a group: take portaliq back as their start page when they
	 * are no resident of any portal any more and the preference is still ours.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function left(string $gid, string $uid): void {
		if ($this->namedByAPortal(gid: $gid) === false) {
			return;
		}

		if ($this->config->getUserValue($uid, self::CORE, self::DEFAULT_APP, '') !== Application::APP_ID) {
			return;
		}

		if ($this->residents->residentPortalOf(uid: $uid) !== null) {
			return;
		}

		$this->config->deleteUserValue($uid, self::CORE, self::DEFAULT_APP);
	}//end left()

	/**
	 * Whether a published portal names this group as its resident or business group.
	 *
	 * @param string $gid The group id.
	 *
	 * @return bool
	 */
	private function namedByAPortal(string $gid): bool {
		if ($gid === '') {
			return false;
		}

		foreach ($this->portals->allPublishedPortals() as $portal) {
			if (is_array($portal) === true && array_key_exists($gid, $this->residents->portalGroups(portal: $portal)) === true) {
				return true;
			}
		}

		return false;
	}//end namedByAPortal()
}//end class
