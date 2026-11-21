<?php

/**
 * Portaliq Portal Session Scope
 *
 * Decides whether a session bound to one portal may be used on the request
 * being served (portal-scoping-and-auth Task 4).
 *
 * @category Auth
 * @package  OCA\Portaliq\Auth
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
 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-session-must-be-scoped-to-the-portal-that-minted-it
 */

declare(strict_types=1);

namespace OCA\Portaliq\Auth;

use OCA\Portaliq\Kernel\Support\SessionTrust;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IRequest;
use Throwable;

/**
 * Keeps a portal-bound session on the portal that minted it.
 *
 * The serving portal is resolved the way the traffic collector resolves it:
 * the verified host first, and a named `portal` only when the host resolves
 * to no portal. So a session minted for portal A is refused on portal B's
 * domain whatever the request names, and refused on the platform host when
 * the request names portal B. A request that is served by no portal at all
 * (the platform host, no portal named) keeps the organisation scope the
 * session had before this change. A failure to resolve refuses.
 *
 * The serving portal's `authentication.minTrust` is a floor every session
 * must meet: a session below it is refused on that portal exactly as one
 * from another portal is, bound or not. A session at `high` meets every
 * floor, so an unbound one is admitted without resolving anything.
 *
 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-session-must-be-scoped-to-the-portal-that-minted-it
 */
class PortalSessionScope {

	/**
	 * Constructor.
	 *
	 * @param IRequest       $request  The request being served.
	 * @param PortalResolver $portals  Resolves the serving portal.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly PortalResolver $portals,
	) {
	}//end __construct()

	/**
	 * Whether a session bound to this portal, at this trust level, may be used on this request.
	 *
	 * @param string $portal The slug the session is bound to; '' for a session bound to no portal.
	 * @param string $trust  The session's normalised trust level.
	 *
	 * @return bool True when the session may be used here.
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-session-must-be-scoped-to-the-portal-that-minted-it
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	public function admits(string $portal, string $trust = 'high'): bool {
		if ($portal === '' && (new SessionTrust())->trustSatisfies(subjectTrust: $trust, minTrust: 'high') === true) {
			return true;
		}

		$named = $this->request->getParam('portal');
		if (is_string($named) === false) {
			$named = null;
		}

		try {
			$serving = $this->portals->resolveForCollector(request: $this->request, portalSlug: $named);
		} catch (Throwable $e) {
			return false;
		}

		if ($serving === null) {
			return true;
		}

		if ($portal !== '' && ($serving['slug'] ?? null) !== $portal) {
			return false;
		}

		return $this->meetsFloor(serving: $serving, trust: $trust);
	}//end admits()

	/**
	 * Whether the trust level meets the serving portal's declared floor.
	 *
	 * A portal that declares no floor, or whose authentication block is
	 * malformed, has the `low` floor every session meets; a malformed block
	 * already offers no way in, so no session is minted under it.
	 *
	 * @param array<string, mixed> $serving The serving portal.
	 * @param string               $trust   The session's normalised trust level.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed
	 */
	private function meetsFloor(array $serving, string $trust): bool {
		$floor = PortalAuthConfig::fromPortal(portal: $serving)->minTrust();

		return (new SessionTrust())->trustSatisfies(subjectTrust: $trust, minTrust: $floor);
	}//end meetsFloor()
}//end class
