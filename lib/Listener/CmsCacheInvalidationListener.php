<?php

/**
 * Portaliq CMS Cache Invalidation Listener
 *
 * Drops cached content for a portal the moment one of its objects is
 * written (ADR-086 §9).
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Portaliq\Service\CmsReader;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Invalidates the CMS content cache on a content write.
 *
 * WHY EVENT-DRIVEN AND NOT EXPIRY. The read cache holds negative results too —
 * "there is no page at this route" is cached exactly like a page, because
 * otherwise every 404 would hit the database. That is correct until an editor
 * creates the missing page: with expiry alone, the route keeps 404ing for the
 * rest of the TTL while the object plainly exists, and the editor concludes
 * the CMS is broken. They would be right.
 *
 * This was not hypothetical. It happened during development on the very first
 * page created after the cache landed, and the symptom was a console 404 on a
 * page that rendered — the kind of thing that survives for months because
 * nothing looks wrong.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
 */
class CmsCacheInvalidationListener implements IEventListener {

	/**
	 * Schemas whose writes affect published content.
	 *
	 * @var string[]
	 */
	private const CMS_SCHEMAS = ['portal', 'menu', 'page', 'glossaryTerm'];


	/**
	 * Constructor.
	 *
	 * @param CmsReader       $reader The reader owning the cache.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CmsReader $reader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Handle an OpenRegister object write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public function handle(Event $event): void {
		if (
			($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
			&& ($event instanceof ObjectDeletedEvent) === false
		) {
			return;
		}

		try {
			$object = $event->getObject();
			$data = $object->getObject();
			if (is_array($data) === false) {
				return;
			}

			$portal = (string)($data['portal'] ?? $data['slug'] ?? '');
			if ($portal === '') {
				return;
			}

			// Cheap enough to run unconditionally: this is a cache drop, not
			// I/O, and getting it wrong the other way (skipping an
			// invalidation) is invisible until someone reports stale content.
			$this->reader->invalidate(portal: $portal);
		} catch (Throwable $e) {
			// Never let a cache concern break a write. A stale cache is a
			// nuisance; a failed save is data loss.
			$this->logger->warning(
				'Portaliq: CMS cache invalidation failed',
				['reason' => $e->getMessage()]
			);
		}
	}//end handle()


	/**
	 * The schemas this listener cares about.
	 *
	 * Exposed for the unit test, so the list cannot drift from the schemas the
	 * reader actually caches without something noticing.
	 *
	 * @return string[] The schema slugs.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-public-content-reads-must-be-cached-keyed-by-audience
	 */
	public static function cmsSchemas(): array {
		return self::CMS_SCHEMAS;
	}//end cmsSchemas()


}//end class
