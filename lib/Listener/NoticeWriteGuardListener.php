<?php

/**
 * Portaliq Notice Write Guard Listener
 *
 * Refuses a portal notice whose end is not after its start
 * (operate-maintenance-notice REQ-OMN-003). OpenRegister keeps no rule that
 * compares two properties, so portaliq checks it on the write.
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
 * @spec openspec/specs/portal-notices/spec.md#requirement-page-editors-manage-notices-req-omn-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stops a notice write whose window is empty or backwards.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/portal-notices/spec.md#requirement-page-editors-manage-notices-req-omn-003
 */
class NoticeWriteGuardListener implements IEventListener {

	/**
	 * OpenRegister's schema mapper, resolved lazily.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For the lazy OpenRegister lookup.
	 * @param IL10N              $l10n      Translates the refusal.
	 * @param LoggerInterface    $logger    Records a schema lookup that failed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Check a notice being created or updated.
	 *
	 * @param Event $event The OpenRegister event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notices/spec.md#requirement-page-editors-manage-notices-req-omn-003
	 */
	public function handle(Event $event): void {
		$entity = null;
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
		} else if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		}

		if ($entity === null || $this->isNotice(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$notice = (array)($entity->getObject() ?? []);
		if (self::windowIsValid(startsAt: $notice['startsAt'] ?? null, endsAt: $notice['endsAt'] ?? null) === false) {
			$event->stopPropagation();
			$event->setErrors(['message' => $this->l10n->t('The end must be after the start.')]);
		}
	}//end handle()


	/**
	 * Whether a window has a readable start and an end after it.
	 *
	 * @param mixed $startsAt The stored start.
	 * @param mixed $endsAt   The stored end.
	 *
	 * @return bool True when the end is after the start.
	 */
	private static function windowIsValid(mixed $startsAt, mixed $endsAt): bool {
		if (is_string($startsAt) === false || is_string($endsAt) === false) {
			return false;
		}

		try {
			return new DateTimeImmutable($endsAt) > new DateTimeImmutable($startsAt);
		} catch (Throwable $e) {
			return false;
		}
	}//end windowIsValid()


	/**
	 * Whether the object belongs to portaliq's own notice schema.
	 *
	 * @param string $schema The object's schema id.
	 *
	 * @return bool True for a portal notice.
	 */
	private function isNotice(string $schema): bool {
		try {
			$notice = $this->container->get(self::SCHEMA_MAPPER)->findByApplicationAndSlug(slug: PortalNoticeReader::SCHEMA, application: Application::APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: notice write guard could not read the schema', ['reason' => $e->getMessage()]);
			return false;
		}

		return $notice !== null && $schema !== '' && $schema === (string)$notice->getId();
	}//end isNotice()
}//end class
