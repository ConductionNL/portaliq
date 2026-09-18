<?php

/**
 * Portaliq Portal Case Access Guard
 *
 * Whether the staff user in front of us may raise an ask on this case.
 *
 * What the check actually is, stated plainly rather than implied: the ADR-023
 * action `portal.ask-partner` must be granted to the user's groups, AND the
 * case must be readable through OpenRegister with RBAC and multitenancy ON, so
 * the read is judged against this user's own rights rather than portaliq's
 * trusted-intermediary posture. A finer per-object write permission belongs to
 * OpenRegister; when it exposes one, this guard is where it goes.
 *
 * Fail closed on every uncertainty: openregister absent, the object missing,
 * the read throwing. Asking a partner puts a request in an outside party's
 * name, so the wrong answer here is expensive in a way a 403 is not.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tasks
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
 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tasks;

use OCA\Portaliq\Service\ActionAuthService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Decides whether a staff user may ask a partner about a case.
 *
 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
 */
class PortalCaseAccessGuard {
	/**
	 * The ADR-023 action raising an ask is gated by.
	 */
	public const ACTION = 'portal.ask-partner';

	/**
	 * The ADR-023 action reviewing a change proposal is gated by.
	 *
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
	 */
	public const ACTION_REVIEW_PROPOSAL = 'portal.review-proposal';

	/**
	 * OpenRegister's object service, resolved lazily so portaliq still boots
	 * on an instance without it.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Constructor.
	 *
	 * @param ActionAuthService $actionAuth The action matrix.
	 * @param ContainerInterface $container Resolves OpenRegister's service.
	 */
	public function __construct(
		private readonly ActionAuthService $actionAuth,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether this user may raise an ask on this case.
	 *
	 * @param IUser $user The staff user.
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
	 */
	public function mayAsk(IUser $user, string $register, string $schema, string $id): bool {
		return $this->mayAct(user: $user, register: $register, schema: $schema, id: $id, action: self::ACTION);
	}//end mayAsk()

	/**
	 * Whether this user may perform one gated act on one record.
	 *
	 * @param IUser $user The staff user.
	 * @param string $register The register the record lives in.
	 * @param string $schema The schema the record lives in.
	 * @param string $id The record.
	 * @param string $action The ADR-023 action being gated.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
	 */
	public function mayAct(IUser $user, string $register, string $schema, string $id, string $action): bool {
		if ($register === '' || $schema === '' || $id === '') {
			return false;
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: $action);
		} catch (OCSForbiddenException $forbidden) {
			return false;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		try {
			// RBAC and multitenancy ON: this read is judged against the user's
			// own rights, unlike every other read portaliq makes.
			$rows = $objectService->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema, 'id' => $id], 'limit' => 1, 'offset' => 0],
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $refused) {
			return false;
		}

		return is_array($rows) === true && $rows !== [];
	}//end mayAct()

	/**
	 * OpenRegister's object service, or null when it is not installed.
	 *
	 * @return object|null
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $missing) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end objectService()
}//end class
