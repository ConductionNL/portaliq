<?php

/**
 * Portaliq Audit Trail Service
 *
 * Writes the portal's proof records into OpenRegister's audit trail: every
 * portal mutation (create/update/forward), every download, every confirmed
 * task completion and every session event (login/logout/refresh) becomes one
 * hash-chained row in OpenRegister's audit trail with the action
 * `portaliq.<verb>`. These rows back the WMEBV burden of proof of delivery
 * (~Awb 2:25). Until change consume-or-audit-trail-proof-records the portal
 * kept them as objects in its own register; the repair step
 * MovePortalAuditEntries moves those records here.
 *
 * A row is a FACT (subject, organisation, session token id, app, verb, target
 * register/schema/id, time) and NEVER carries payload content, so the trail
 * cannot become a second copy of the domain object.
 *
 * Failure isolation: a write failure here never fails the audited action.
 * `record()` catches everything, logs the gap and never throws.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/consume-or-audit-trail-proof-records/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTime;
use OCA\Portaliq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Failure-isolated writer and counter of the portal's rows in OpenRegister's
 * audit trail.
 *
 * @spec openspec/changes/consume-or-audit-trail-proof-records/tasks.md#T01
 */
class AuditTrailService {
	/**
	 * OpenRegister's audit-trail mapper: the only place a proof record is written.
	 */
	private const TRAIL_MAPPER = 'OCA\\OpenRegister\\Db\\AuditTrailMapper';

	/**
	 * The action prefix of every portal row in the audit trail.
	 */
	public const ACTION_PREFIX = Application::APP_ID . '.';

	/**
	 * The verbs the portal records.
	 */
	public const VERBS = ['create', 'update', 'forward', 'download', 'login', 'logout', 'refresh', 'complete'];

	/**
	 * A uuid, so a target that is an object is linked to that object's history.
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's audit-trail mapper, which may be absent.
	 * @param LoggerInterface $logger Records a write failure for reconciliation; never rethrown.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one audit fact. NEVER throws.
	 *
	 * @param string $verb One of self::VERBS.
	 * @param string $subjectRef The subject the event belongs to.
	 * @param string $organisation The subject's tenant.
	 * @param string $register The target register (or the app id of a forwarded action).
	 * @param string $schema The target schema (or the action id).
	 * @param string $id The target object id (may be empty for session events).
	 * @param string $jti The acting session's token id, when known.
	 * @param string $appId The contributing app id recording the entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consume-or-audit-trail-proof-records/tasks.md#T01
	 */
	public function record(
		string $verb,
		string $subjectRef,
		string $organisation,
		string $register,
		string $schema,
		string $id,
		string $jti = '',
		string $appId = Application::APP_ID,
	): void {
		$fact = [
			'jti' => $jti,
			'subjectRef' => $subjectRef,
			'organisation' => $organisation,
			'appId' => $appId,
			'verb' => $verb,
			'register' => $register,
			'schema' => $schema,
			'targetId' => $id,
		];

		try {
			$this->append(fact: $fact, uuid: self::newUuid(), created: new DateTime());
		} catch (Throwable $e) {
			// The audited action already happened: log the gap, never propagate.
			$this->logger->warning('Portaliq: audit record failed', ['verb' => $verb, 'reason' => $e->getMessage()]);
		}
	}//end record()

	/**
	 * Write one fact as an audit-trail row with a given uuid and time. The
	 * repair step uses it to move an old record with its own uuid and time.
	 *
	 * @param array<string, mixed> $fact The fact fields (see record()).
	 * @param string $uuid The row's uuid.
	 * @param DateTime $created When the fact happened.
	 *
	 * @return void
	 *
	 * @throws Throwable When OpenRegister is absent or the insert fails.
	 *
	 * @spec openspec/changes/consume-or-audit-trail-proof-records/tasks.md#T01
	 */
	public function append(array $fact, string $uuid, DateTime $created): void {
		$verb = (string)($fact['verb'] ?? '');
		$subject = (string)($fact['subjectRef'] ?? '');
		$target = (string)($fact['targetId'] ?? '');

		$class = $this->trailEntityClass();
		$row = new $class();
		$row->setUuid($uuid);
		$row->setAction(self::ACTION_PREFIX . $verb);
		$row->setUser($subject);
		$row->setUserName($subject);
		$row->setChanged(
			[
				'appId' => (string)($fact['appId'] ?? Application::APP_ID),
				'register' => (string)($fact['register'] ?? ''),
				'schema' => (string)($fact['schema'] ?? ''),
				'targetId' => $target,
			]
		);
		if ((string)($fact['jti'] ?? '') !== '') {
			$row->setSession((string)$fact['jti']);
		}

		if ((string)($fact['organisation'] ?? '') !== '') {
			$row->setOrganisationId((string)$fact['organisation']);
		}

		if (preg_match(self::UUID_PATTERN, $target) === 1) {
			$row->setObjectUuid($target);
		}

		$row->setCreated($created);

		$this->mapper()->insertAuditTrails([$row]);
	}//end append()

	/**
	 * Whether the audit trail already holds a row with this uuid.
	 *
	 * @param string $uuid The row uuid.
	 *
	 * @return bool
	 *
	 * @throws Throwable When OpenRegister is absent.
	 *
	 * @spec openspec/changes/consume-or-audit-trail-proof-records/tasks.md#T02
	 */
	public function has(string $uuid): bool {
		return $this->mapper()->findAll(limit: 1, filters: ['uuid' => $uuid]) !== [];
	}//end has()

	/**
	 * Count-only exposure for MetricsController: the number of portal rows
	 * in the audit trail per verb, never a subject, target or payload.
	 * Degrades to zero per verb when OpenRegister is unavailable.
	 *
	 * @return array<string, int> Counts keyed by verb.
	 *
	 * @spec openspec/changes/consume-or-audit-trail-proof-records/tasks.md#T03
	 */
	public function countsByVerb(): array {
		$counts = array_fill_keys(self::VERBS, 0);
		try {
			$mapper = $this->mapper();
			foreach (self::VERBS as $verb) {
				$counts[$verb] = count($mapper->findAll(filters: ['action' => self::ACTION_PREFIX . $verb]));
			}
		} catch (Throwable $e) {
			$this->logger->debug('Portaliq: audit count read failed', ['reason' => $e->getMessage()]);
		}

		return $counts;
	}//end countsByVerb()

	/**
	 * A random (version 4) uuid for a new row.
	 *
	 * @return string
	 */
	private static function newUuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end newUuid()

	/**
	 * OpenRegister's audit-trail mapper.
	 *
	 * @return mixed
	 *
	 * @throws Throwable When OpenRegister is not installed.
	 */
	private function mapper(): mixed {
		return $this->container->get(self::TRAIL_MAPPER);
	}//end mapper()

	/**
	 * OpenRegister's audit-trail entity class, named here rather than imported
	 * because OpenRegister is a sibling app that may be absent.
	 *
	 * @return mixed
	 */
	private function trailEntityClass(): mixed {
		return 'OCA\\OpenRegister\\Db\\AuditTrail';
	}//end trailEntityClass()
}//end class
