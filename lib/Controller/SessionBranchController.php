<?php

/**
 * Portaliq Session Branch Controller
 *
 * The branch choice of a whole-company business session
 * (signin-eherkenning-branch T05): `GET /portal/api/session/branches` lists
 * the company's branches, `POST /portal/api/session/branch` re-issues the
 * bearer for one of them, or for the whole company with ''. Refused for a
 * session the login restricted to a branch and for a branch that is not the
 * company's.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/signin-eherkenning-branch/specs/signin-eherkenning-branch/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Branch\BranchChoice;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The branch a business session acts for.
 *
 * @spec openspec/changes/signin-eherkenning-branch/specs/signin-eherkenning-branch/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
 */
class SessionBranchController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request The request.
	 * @param PortalSessionService $session Resolves and re-issues the bearer.
	 * @param BranchChoice         $choice  The branches the session may choose.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly BranchChoice $choice,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The branch in effect and the branches the session may choose.
	 *
	 * @return JSONResponse `{branch, restricted, branches}`, 401 without a session.
	 *
	 * @spec openspec/changes/signin-eherkenning-branch/specs/signin-eherkenning-branch/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function branches(): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			[
				'branch'     => (string)($subject['branch'] ?? ''),
				'restricted' => (($subject['branchRestricted'] ?? false) === true),
				'branches'   => $this->choice->branchesFor(subject: $subject),
			]
		);
	}//end branches()

	/**
	 * Re-issue the bearer for one branch, or for the whole company with ''.
	 *
	 * @param string $branch The 12-digit branch number, or ''.
	 *
	 * @return JSONResponse The new bearer; 401 without a session; 403 when refused.
	 *
	 * @spec openspec/changes/signin-eherkenning-branch/specs/signin-eherkenning-branch/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function choose(string $branch = ''): JSONResponse {
		$header  = $this->request->getHeader('Authorization');
		$subject = $this->session->resolveFromBearer($header);
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$branch = trim($branch);
		if ($this->choice->allows(subject: $subject, branch: $branch) === false) {
			return new JSONResponse(['error' => 'branch_refused'], Http::STATUS_FORBIDDEN);
		}

		$issued = $this->session->rebranchSession($header, $branch);
		if ($issued === null) {
			return new JSONResponse(['error' => 'branch_refused'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			[
				'token'         => $issued['token'],
				'tokenType'     => 'Bearer',
				'expiresAt'     => (int)($issued['expiresAt'] ?? 0),
				'hardExpiresAt' => (int)($issued['hardExpiresAt'] ?? 0),
				'idleTimeout'   => (int)($issued['idleTimeout'] ?? 0),
				'branch'        => $branch,
			]
		);
	}//end choose()
}//end class
