<?php

/**
 * Portaliq glossary relation guard: vetoes a relation to a term on another portal
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
 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Cms\GlossaryRelations;
use OCA\Portaliq\Service\CmsReader;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vetoes a glossary term write that relates to a term of another portal.
 *
 * A schema cannot say "same portal as this object", so the rule lives in the
 * pre-write hook. A relation that does not resolve is refused as well, so a
 * failed lookup never lets a cross-portal relation through.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-3
 */
class GlossaryRelationGuardListener implements IEventListener {

	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's schema mapper.
	 * @param CmsReader          $reader    Reads the terms of a portal.
	 * @param GlossaryRelations  $relations Decides which relations leave the portal.
	 * @param IL10N              $l10n      Translates the refusal.
	 * @param LoggerInterface    $logger    Logs a failed check.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly CmsReader $reader,
		private readonly GlossaryRelations $relations,
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
	 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-3
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
	 * Refuse the write when a relation leaves the portal.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-write event.
	 * @param object                                  $entity The object being written.
	 *
	 * @return void
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, object $entity): void {
		if ($this->isTerm(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$term      = (array)($entity->getObject() ?? []);
		$relations = (array)($term['relations'] ?? []);
		if ($relations === []) {
			return;
		}

		$portal  = (string)($term['portal'] ?? '');
		$foreign = [];
		if ($portal === '') {
			$foreign = array_map('strval', array_filter($relations, 'is_scalar'));
		}

		if ($portal !== '') {
			$foreign = $this->relations->foreign(
				portal: $portal,
				relations: $relations,
				portalTerms: $this->reader->rowsForCheck(portal: $portal, schema: 'glossaryTerm')
			);
		}

		if ($foreign !== []) {
			$event->stopPropagation();
			$message = $this->l10n->t('A term can only relate to terms on its own portal. Not found there: %s.', [implode(', ', $foreign)]);
			$event->setErrors(['message' => $message]);
		}
	}//end check()

	/**
	 * Whether an object's schema is portaliq's glossary term schema.
	 *
	 * @param string $schema The object's schema id.
	 *
	 * @return bool
	 */
	private function isTerm(string $schema): bool {
		try {
			$term = $this->container->get(self::SCHEMA_MAPPER)->findByApplicationAndSlug(slug: 'glossaryTerm', application: Application::APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: glossary guard could not read the schema', ['reason' => $e->getMessage()]);
			return false;
		}

		return $term !== null && $schema !== '' && $schema === (string)$term->getId();
	}//end isTerm()
}//end class
