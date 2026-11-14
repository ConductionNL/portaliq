<?php

/**
 * Portaliq portal images guard
 *
 * Refuses a portal saved with a favicon that is not a PNG, SVG or ICO, or an
 * image of another portal's media library.
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
 * @spec openspec/changes/portal-identity-from-the-admin/specs
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Service\Cms\PortalImageChoice;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The save path of the portal settings form, whichever client writes the
 * portal: the rules live in PortalImageChoice.
 *
 * @template-implements IEventListener<\OCP\EventDispatcher\Event>
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs
 */
class PortalImagesGuardListener implements IEventListener {

	use SchemaWriteGuard;

	/**
	 * The schema this guard watches.
	 */
	private const GUARDED_SCHEMA = 'portal';

	/**
	 * The guard's name in the log.
	 */
	private const GUARD_NAME = 'portal images guard';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For OpenRegister's schema mapper.
	 * @param PortalImageChoice  $images    The image rules.
	 * @param LoggerInterface    $logger    For a schema that cannot be read.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalImageChoice $images,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the write when the portal's images break a rule.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The write.
	 * @param object                                  $entity The portal entity being written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, object $entity): void {
		if ($this->guardsSchema(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$refusal = $this->images->refusal(portal: (array)($entity->getObject() ?? []));
		if ($refusal !== null) {
			$event->stopPropagation();
			$event->setErrors(['message' => $refusal]);
		}
	}//end check()
}//end class
