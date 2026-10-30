<?php

/**
 * Portaliq Portal Admin Groups Guard Listener
 *
 * Refuses a portal write that changes `adminGroups` by anyone but an instance
 * administrator (operate-portals-per-organisation REQ-OPO-005). A member of a
 * portal's admin group may change the rest of that portal; handing out the
 * keys stays with the hosting party.
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stops a change to a portal's `adminGroups` made by a non-administrator.
 *
 * A write without a signed-in user (a repair step, a background job, `occ`)
 * passes: those run as the installation, not as an organisation.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
 */
class PortalAdminGroupsGuardListener implements IEventListener {

	/**
	 * OpenRegister's schema mapper, resolved lazily.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   For the lazy OpenRegister lookup.
	 * @param IUserSession       $userSession The user making the write.
	 * @param PortalAdminScope   $scope       Who may hand out admin groups.
	 * @param IL10N              $l10n        Translates the refusal.
	 * @param LoggerInterface    $logger      Records a schema lookup that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
		private readonly PortalAdminScope $scope,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Check a portal being created or updated.
	 *
	 * @param Event $event The OpenRegister event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->check(event: $event, entity: $event->getObject(), old: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->check(event: $event, entity: $event->getNewObject(), old: $event->getOldObject());
		}
	}//end handle()


	/**
	 * Refuse the write when it changes `adminGroups` and the writer is no
	 * instance administrator.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-write event.
	 * @param object                                  $entity The object being written.
	 * @param object|null                             $old    The stored object, on update.
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, object $entity, ?object $old): void {
		$user = $this->userSession->getUser();
		if ($user === null || $this->scope->mayChangeAdminGroups(user: $user) === true) {
			return;
		}

		if ($this->isPortal(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$before = [];
		if ($old !== null) {
			$before = $this->scope->groupsOf(portal: (array)($old->getObject() ?? []));
		}

		$after = $this->scope->groupsOf(portal: (array)($entity->getObject() ?? []));
		if (self::sameGroups(before: $before, after: $after) === true) {
			return;
		}

		$event->stopPropagation();
		$event->setErrors(['message' => $this->l10n->t('Only an administrator of this installation can change who administers a portal.')]);
	}//end check()


	/**
	 * Whether two group lists name the same groups, in any order.
	 *
	 * @param array<int, string> $before The stored groups.
	 * @param array<int, string> $after  The written groups.
	 *
	 * @return bool True when nothing changed.
	 */
	private static function sameGroups(array $before, array $after): bool {
		sort($before);
		sort($after);

		return $before === $after;
	}//end sameGroups()


	/**
	 * Whether the object belongs to portaliq's own portal schema.
	 *
	 * Fail-closed: when the schema cannot be read the object is treated as a
	 * portal, which refuses only a write that changes an `adminGroups` field.
	 *
	 * @param string $schema The object's schema id.
	 *
	 * @return bool True for a portal, or when that cannot be told.
	 */
	private function isPortal(string $schema): bool {
		try {
			$portal = $this->container->get(self::SCHEMA_MAPPER)->findByApplicationAndSlug(slug: 'portal', application: Application::APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: admin groups guard could not read the schema', ['reason' => $e->getMessage()]);
			return true;
		}

		return $portal !== null && $schema !== '' && $schema === (string)$portal->getId();
	}//end isPortal()
}//end class
