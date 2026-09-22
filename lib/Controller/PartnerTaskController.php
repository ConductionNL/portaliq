<?php

/**
 * Portaliq Partner Task Controller
 *
 * The handler's side of a partner task: asking an outside party for something
 * from the case. The partner's side is the ordinary task surface, which
 * already serves every audience the registry discovers.
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
 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Tasks\PartnerAskService;
use OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Raises a partner ask from a case.
 *
 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
 */
class PartnerTaskController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PartnerAskService $asks Resolves the partner and raises the task.
	 * @param PortalCaseAccessGuard $guard Decides whether this user may ask.
	 * @param IUserSession $userSession The handler making the request.
	 */
	public function __construct(
		IRequest $request,
		private readonly PartnerAskService $asks,
		private readonly PortalCaseAccessGuard $guard,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Ask a partner for something from a case.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $caseId The case the ask hangs on.
	 * @param string $title What the partner is asked for.
	 * @param string $description The ask, in full.
	 * @param string $dueAt When it is wanted, ISO 8601.
	 * @param array<string, mixed> $partner `subjectRef`, or `kvk`, `email` and `name`.
	 * @param array<int, array<string, mixed>> $uploadRules What must be uploaded.
	 *
	 * @return JSONResponse The task and the account it went to, or a refusal.
	 *
	 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the parameters are the
	 * declared fields of the ask; an options array would lose their types.
	 */
	#[NoAdminRequired]
	public function ask(
		string $register,
		string $schema,
		string $caseId,
		string $title,
		string $description = '',
		string $dueAt = '',
		array $partner = [],
		array $uploadRules = [],
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// The write check comes first: a user without it must not be able to
		// tell, by the shape of the refusal, whether the case even exists or
		// whether the partner is known.
		if ($this->guard->mayAsk(user: $user, register: $register, schema: $schema, id: $caseId) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$asked = $this->asks->ask(
			handler: [
				'uid' => $user->getUID(),
				'subjectRef' => $user->getUID(),
				'audience' => 'handler',
				'organisation' => (string)$this->request->getParam('organisation', ''),
				'trust' => 'high',
			],
			partner: $partner,
			ask: [
				'title' => $title,
				'description' => $description,
				'dueAt' => $dueAt,
				'uploadRules' => $uploadRules,
				'register' => $register,
				'schema' => $schema,
				'caseId' => $caseId,
			]
		);
		if ($asked === null) {
			return new JSONResponse(['error' => 'not_raised'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($asked);
	}//end ask()
}//end class
