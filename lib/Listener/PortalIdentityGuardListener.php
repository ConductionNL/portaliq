<?php

/**
 * Portaliq portal identity guard
 *
 * Refuses a portal save whose favicon, logo or hero image is not an image of
 * the portal's own media library, or whose favicon is not a PNG, SVG or ICO.
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Cms\PortalIdentityImages;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vetoes a portal write through OpenRegister's pre-write hooks.
 *
 * The portal settings form saves through OpenRegister's object API, so this
 * hook is the save path. The rules depend on other objects (the media item and
 * its file), which a schema cannot declare. A stopped event makes OpenRegister
 * refuse the write with the message, which is what the administrator reads.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
 *
 * @template-implements IEventListener<Event>
 */
class PortalIdentityGuardListener implements IEventListener {

	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface   $container Resolves OpenRegister's schema mapper.
	 * @param PortalIdentityImages $images    The rules for the three images.
	 * @param LoggerInterface      $logger    Logs a failed schema read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly PortalIdentityImages $images,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check one pre-write event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->check(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->check(event: $event, entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Refuse the write when a rule says so.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-write event.
	 * @param object                                  $entity The object being written.
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, object $entity): void {
		$portal = (array)($entity->getObject() ?? []);
		if ($this->namesAnImage(portal: $portal) === false || $this->isPortal(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$refusal = $this->images->refusal(portal: $portal);
		if ($refusal !== null) {
			$event->stopPropagation();
			$event->setErrors(['message' => $refusal]);
		}
	}//end check()

	/**
	 * Whether the object sets any of the three image fields; most writes do
	 * not, and those skip the schema read.
	 *
	 * @param array<string, mixed> $portal The object's fields.
	 *
	 * @return bool
	 */
	private function namesAnImage(array $portal): bool {
		foreach (PortalIdentityImages::FIELDS as $field) {
			if (($portal[$field] ?? '') !== '' && ($portal[$field] ?? null) !== null) {
				return true;
			}
		}

		return false;
	}//end namesAnImage()

	/**
	 * Whether an object's schema is portaliq's portal schema.
	 *
	 * @param string $schema The object's schema id.
	 *
	 * @return bool
	 */
	private function isPortal(string $schema): bool {
		try {
			$portal = $this->container->get(self::SCHEMA_MAPPER)->findByApplicationAndSlug(slug: 'portal', application: Application::APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: portal identity guard could not read the schema', ['reason' => $e->getMessage()]);
			return false;
		}

		return $portal !== null && $schema !== '' && $schema === (string)$portal->getId();
	}//end isPortal()
}//end class
