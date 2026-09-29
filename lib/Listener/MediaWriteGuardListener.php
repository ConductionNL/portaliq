<?php

/**
 * Portaliq media write guard
 *
 * The media library's two write rules: an image needs alternative text, and
 * an item a published page uses is not deleted.
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
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Cms\MediaLibraryReader;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vetoes a media write through OpenRegister's pre-write hooks.
 *
 * Both rules live here rather than in the schema. OpenRegister keeps no
 * conditional (`if`/`then`) schema rule, so "alternative text when the kind is
 * image" cannot be declared, and "not while a page uses it" depends on other
 * objects. A stopped event makes OpenRegister refuse the write with the
 * message, which is what the editor reads.
 *
 * @template-implements IEventListener<Event>
 */
class MediaWriteGuardListener implements IEventListener {

	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's schema mapper.
	 * @param MediaLibraryReader $library   Names the pages that use an item.
	 * @param IL10N              $l10n      Translates the refusal.
	 * @param LoggerInterface    $logger    Logs a failed check.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly MediaLibraryReader $library,
		private readonly IL10N $l10n,
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
	 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectDeletingEvent) {
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
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent|ObjectDeletingEvent $event  The pre-write event.
	 * @param object                                                      $entity The object being written.
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent|ObjectDeletingEvent $event, object $entity): void {
		if ($this->isMedia(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$item    = (array)($entity->getObject() ?? []);
		$refusal = $this->refusal(event: $event, item: $item, id: (string)$entity->getUuid());
		if ($refusal !== null) {
			$event->stopPropagation();
			$event->setErrors(['message' => $refusal]);
		}
	}//end check()

	/**
	 * Why this write is refused, or null.
	 *
	 * @param Event                $event The event.
	 * @param array<string, mixed> $item  The item's fields.
	 * @param string               $id    The item id.
	 *
	 * @return string|null
	 */
	private function refusal(Event $event, array $item, string $id): ?string {
		if ($event instanceof ObjectDeletingEvent) {
			$pages = $this->library->pagesUsing(portal: (string)($item['portal'] ?? ''), id: $id);
			if ($pages === []) {
				return null;
			}

			return $this->l10n->t('This item is used on published pages: %s. Remove it there first.', [implode(', ', $pages)]);
		}

		if (($item['kind'] ?? '') === 'image' && trim((string)($item['alt'] ?? '')) === '') {
			return $this->l10n->t('An image in the media library needs alternative text.');
		}

		return null;
	}//end refusal()

	/**
	 * Whether an object's schema is portaliq's media schema.
	 *
	 * @param string $schema The object's schema id.
	 *
	 * @return bool
	 */
	private function isMedia(string $schema): bool {
		try {
			$media = $this->container->get(self::SCHEMA_MAPPER)->findByApplicationAndSlug(slug: 'media', application: Application::APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: media write guard could not read the schema', ['reason' => $e->getMessage()]);
			return false;
		}

		return $media !== null && $schema !== '' && $schema === (string)$media->getId();
	}//end isMedia()
}//end class
