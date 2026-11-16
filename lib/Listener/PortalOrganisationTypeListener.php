<?php

/**
 * Portaliq portal organisation type guard
 *
 * Refuses a portal saved with an organisation type that is not a concept of
 * the TOOI organisation type scheme, and stores the concept's label beside a
 * type that is.
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
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Kernel\Contracts\OrganisationTypeOptionsInterface;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The save path of a portal's organisation type, whichever client writes the
 * portal: the rules live in OrganisationTypeOptions::settle().
 *
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
class PortalOrganisationTypeListener implements IEventListener {

	use SchemaWriteGuard;

	private const GUARDED_SCHEMA = 'portal';

	private const GUARD_NAME = 'portal organisation type guard';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface               $container For the lazy schema lookup.
	 * @param OrganisationTypeOptionsInterface $types     The organisation type rules.
	 * @param LoggerInterface                  $logger    Notes an unreadable schema.
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly OrganisationTypeOptionsInterface $types,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a type outside the list, or store its label on the portal.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The event.
	 * @param object                                  $entity The portal being saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, object $entity): void {
		if ($this->guardsSchema(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$old = [];
		if ($event instanceof ObjectUpdatingEvent) {
			$old = (array)($event->getOldObject()?->getObject() ?? []);
		}

		$settled = $this->types->settle(portal: (array)($entity->getObject() ?? []), old: $old);
		if ($settled['refusal'] !== null) {
			$event->stopPropagation();
			$event->setErrors(['message' => $settled['refusal']]);
			return;
		}

		$entity->setObject($settled['portal']);
	}//end check()
}//end class
