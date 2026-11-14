<?php

/**
 * SchemaWriteGuard
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Kernel\AppIdentity;
use OCP\EventDispatcher\Event;
use Throwable;

/**
 * A pre-write guard for objects of one portaliq schema: it hands the object
 * being created or updated to the using class's check(), and recognises the
 * schema it guards. The using class names that schema's slug in
 * GUARDED_SCHEMA and itself in GUARD_NAME, and has a $container and $logger.
 */
trait SchemaWriteGuard {
	/**
	 * Check one pre-write event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-form-binding-names-its-destination-and-is-valid-against-it-req-pifo-007
	 * @spec openspec/changes/archive/2026-10-09-portal-cms-content-model/tasks.md#task-3
	 * @spec openspec/specs/portal-notices/spec.md#requirement-page-editors-manage-notices-req-omn-003
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->check(event: $event, entity: $event->getObject());
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->check(event: $event, entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Whether an object's schema id is the guarded schema. A schema that
	 * cannot be read counts as not guarded, and is logged.
	 *
	 * @param string $schema The object's schema id.
	 *
	 * @return bool
	 *
	 * @spec exclude shared schema recognition of the schema guards
	 */
	private function guardsSchema(string $schema): bool {
		try {
			$guarded = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper')
				->findByApplicationAndSlug(slug: self::GUARDED_SCHEMA, application: AppIdentity::APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: ' . self::GUARD_NAME . ' could not read the schema', ['reason' => $e->getMessage()]);
			return false;
		}

		if ($guarded === null || $schema === '') {
			return false;
		}

		return $schema === (string)$guarded->getId();
	}//end guardsSchema()
}//end trait
