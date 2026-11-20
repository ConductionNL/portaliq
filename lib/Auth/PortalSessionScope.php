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
	 * Whether a session bound to this portal may be used on this request.
	 *
	 * @param string $portal The slug the session is bound to; '' for a session bound to no portal.
	 *
	 * @return bool True when the session may be used here.
	 *
	 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-session-must-be-scoped-to-the-portal-that-minted-it
	 */
	public function admits(string $portal): bool {
		if ($portal === '') {
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

		return ($serving['slug'] ?? null) === $portal;
	}//end admits()
}//end class
