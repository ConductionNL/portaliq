<?php

/**
 * Portaliq Portal Report Owner Guard Listener
 *
 * Refuses a portal write that switches error reports on without an
 * existing owner, whichever form or API the write came through.
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
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#1-settings
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Service\PortalReportOwnerCheck;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stops a portal write whose error reports have no existing owner.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
 */
class PortalReportOwnerGuardListener implements IEventListener {

	use SchemaWriteGuard;

	/**
	 * The slug of the schema this guard checks.
	 */
	private const GUARDED_SCHEMA = 'portal';

	/**
	 * How this guard names itself in the log.
	 */
	private const GUARD_NAME = 'portal report owner guard';


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface     $container For the lazy OpenRegister lookup.
	 * @param PortalReportOwnerCheck $owners    Decides whether the owner holds.
	 * @param LoggerInterface        $logger    Records a schema lookup that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalReportOwnerCheck $owners,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Refuse the write when error reports are on without an existing owner.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-write event.
	 * @param object                                  $entity The object being written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
	 */
	protected function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, object $entity): void {
		if ($this->guardsSchema(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$refusal = $this->owners->refusal(portal: (array)($entity->getObject() ?? []));
		if ($refusal !== null) {
			$event->stopPropagation();
			$event->setErrors(['message' => $refusal]);
		}
	}//end check()
}//end class
