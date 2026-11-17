<?php

/**
 * Portaliq page route guard: vetoes a page that takes a route its portal already has
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
 * @spec openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-publishing-must-refuse-content-that-would-render-broken
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Kernel\Contracts\CmsReaderInterface;
use OCA\Portaliq\Service\Cms\PageRouteClaim;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Vetoes a page write when another page of the same portal already holds that
 * route in the same language: two pages on one route render whichever the
 * read finds first. A schema cannot say "unique within this portal", so the
 * rule lives in the pre-write hook, beside the editor's publish check.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-publishing-must-refuse-content-that-would-render-broken
 */
class PageRouteGuardListener implements IEventListener {

	use SchemaWriteGuard;

	/**
	 * The slug of the schema this guard checks.
	 */
	private const GUARDED_SCHEMA = 'page';

	/**
	 * How this guard names itself in the log.
	 */
	private const GUARD_NAME = 'page route guard';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's schema mapper.
	 * @param CmsReaderInterface $reader    Reads the pages of a portal, drafts included.
	 * @param IL10N              $l10n      Translates the refusal.
	 * @param LoggerInterface    $logger    Logs a schema that cannot be read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly CmsReaderInterface $reader,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the write when the page takes a route its portal already has.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-write event.
	 * @param object                                  $entity The object being written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-publishing-must-refuse-content-that-would-render-broken
	 */
	protected function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, object $entity): void {
		if ($this->guardsSchema(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$page   = (array)($entity->getObject() ?? []);
		$portal = $page['portal'] ?? '';
		if (is_string($portal) === false || $portal === '') {
			return;
		}

		$route = (new PageRouteClaim())->duplicate(
			page: $page,
			pageId: (string)($entity->getUuid() ?? ''),
			others: $this->reader->rowsForCheck(portal: $portal, schema: 'page')
		);
		if ($route === '') {
			return;
		}

		$event->stopPropagation();
		$event->setErrors(['message' => $this->l10n->t('Another page of this portal already has the route %s.', [$route])]);
	}//end check()
}//end class
