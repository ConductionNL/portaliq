<?php

/**
 * Portaliq Portal Admin Listener
 *
 * Keeps each portal's administrators to their own portal on OpenRegister's
 * object writes.
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
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Service\Tenancy\PortalAdminGrants;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCA\Portaliq\Service\Tenancy\PortalAdminWriteGate;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Before a write: the PortalAdminWriteGate's refusal stops it, with its
 * message. A move out of the register (ObjectLeavingEvent, a deleting event)
 * is checked like a delete. After a portal write: its `adminGroups` are written into the
 * rules of the content it owns (PortalAdminGrants).
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
 */
class PortalAdminListener implements IEventListener {

	/**
	 * Schema id to slug for the schemas PortalAdminGrants knows, once per request.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $slugs = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface   $container Resolves OpenRegister's schema mapper.
	 * @param PortalAdminWriteGate $gate      Refuses a write outside the user's portal.
	 * @param PortalAdminGrants    $grants    Writes a portal's groups into the rules.
	 * @param LoggerInterface      $logger    Notes a schema lookup that failed.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalAdminWriteGate $gate,
		private readonly PortalAdminGrants $grants,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one OpenRegister object event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectDeletingEvent) {
			$this->guard(event: $event, entity: $event->getObject(), before: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->guard(event: $event, entity: $event->getNewObject(), before: $event->getOldObject());
			return;
		}

		if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectDeletedEvent) {
			$this->grant(entity: $event->getObject(), before: null, deleted: $event instanceof ObjectDeletedEvent);
			return;
		}

		if ($event instanceof ObjectUpdatedEvent) {
			$this->grant(entity: $event->getNewObject(), before: $event->getOldObject(), deleted: false);
		}
	}//end handle()

	/**
	 * Stop a pre-write event the gate refuses.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent|ObjectDeletingEvent $event  The event.
	 * @param object                                                      $entity The row being written.
	 * @param object|null                                                 $before The row before an update.
	 *
	 * @return void
	 */
	private function guard(ObjectCreatingEvent|ObjectUpdatingEvent|ObjectDeletingEvent $event, object $entity, ?object $before): void {
		$schema = $this->slugOf(entity: $entity);
		if ($schema === null) {
			return;
		}

		$previous = null;
		if ($before !== null) {
			$previous = (array)($before->getObject() ?? []);
		}

		$refusal = $this->gate->refusal(schema: $schema, row: (array)($entity->getObject() ?? []), before: $previous);
		if ($refusal !== null) {
			$event->stopPropagation();
			$event->setErrors(['message' => $refusal]);
		}
	}//end guard()

	/**
	 * Write a portal's groups into the rules after the portal was written.
	 *
	 * @param object      $entity  The portal row.
	 * @param object|null $before  The portal before an update.
	 * @param bool        $deleted Whether the portal was deleted.
	 *
	 * @return void
	 */
	private function grant(object $entity, ?object $before, bool $deleted): void {
		if ($this->slugOf(entity: $entity) !== 'portal') {
			return;
		}

		$row      = (array)($entity->getObject() ?? []);
		$slug     = (string)($row['slug'] ?? '');
		$previous = '';
		if ($before !== null) {
			$previous = (string)(((array)($before->getObject() ?? []))['slug'] ?? '');
		}

		if ($deleted === true) {
			$previous = $slug;
			$slug     = '';
		}

		$this->grants->apply(slug: $slug, groups: PortalAdminScope::adminGroupsOf(portal: $row), previousSlug: $previous);
	}//end grant()

	/**
	 * The slug of the row's schema when PortalAdminGrants knows it, else null.
	 *
	 * @param object $entity The row.
	 *
	 * @return string|null
	 */
	private function slugOf(object $entity): ?string {
		if ($this->slugs === null) {
			try {
				$mapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
				$slugs  = [];
				foreach (array_keys(PortalAdminGrants::SCHEMAS) as $slug) {
					$schema = $mapper->findByApplicationAndSlug(slug: $slug, application: AppIdentity::APP_ID);
					if ($schema !== null) {
						$slugs[(string)$schema->getId()] = $slug;
					}
				}

				$this->slugs = $slugs;
			} catch (Throwable $e) {
				// Not cached, so the next write asks again.
				$this->logger->warning('Portaliq: the portal admin listener could not read the schemas', ['reason' => $e->getMessage()]);
				return null;
			}
		}//end if

		return ($this->slugs[(string)$entity->getSchema()] ?? null);
	}//end slugOf()
}//end class
