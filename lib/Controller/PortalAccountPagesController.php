<?php

/**
 * Portaliq portal account pages controller
 *
 * The staff routes behind the "Pages hidden for this client" field on a
 * portal account's page: the pages the installed apps contribute for the
 * account's audience with whether each is hidden for it, and the save of
 * that list. Guarded by the same staff action as the other account screens;
 * the account holder's self-service routes never reach it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\AccountHiddenPagesInterface;
use OCA\Portaliq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Portaliq\Kernel\StaffAction;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Lists and saves the pages hidden for one portal account.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */
class PortalAccountPagesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request     The request.
	 * @param AccountHiddenPagesInterface $pages       The account's hidden pages.
	 * @param ActionAuthServiceInterface  $actionAuth  The staff action check.
	 * @param IUserSession                $userSession The signed-in user.
	 */
	public function __construct(
		IRequest $request,
		private readonly AccountHiddenPagesInterface $pages,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The pages the installed apps contribute for the account's audience,
	 * each with whether it is hidden for this account.
	 *
	 * @param string $subjectRef The account's subject reference.
	 *
	 * @return JSONResponse `pages`, 401 without a session, 403 without the
	 *                      staff action, or 404 for an unknown account.
	 *
	 * @auth staff-action portal.provision, checked in the body before the
	 *       account is read.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	#[NoAdminRequired]
	public function index(string $subjectRef): JSONResponse {
		$refused = $this->refusal();
		if ($refused !== null) {
			return $refused;
		}

		$account = $this->pages->accountBySubjectRef(subjectRef: $subjectRef);
		if ($account === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['pages' => $this->pages->listFor(account: $account)]);
	}//end index()

	/**
	 * Save the pages hidden for the account.
	 *
	 * @param string $subjectRef The account's subject reference.
	 *
	 * @return JSONResponse The list as stored, 400 when `hiddenPages` is not
	 *                      a list, 401 without a session, 403 without the
	 *                      staff action, 404 for an unknown account, or 502
	 *                      when the write failed.
	 *
	 * @auth staff-action portal.provision, checked in the body before the
	 *       account is read.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	#[NoAdminRequired]
	public function update(string $subjectRef): JSONResponse {
		$refused = $this->refusal();
		if ($refused !== null) {
			return $refused;
		}

		$hidden = $this->request->getParam('hiddenPages', null);
		if (is_array($hidden) === false) {
			return new JSONResponse(['error' => 'invalid_hidden_pages'], Http::STATUS_BAD_REQUEST);
		}

		$account = $this->pages->accountBySubjectRef(subjectRef: $subjectRef);
		if ($account === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$saved = $this->pages->save(account: $account, hidden: $hidden);
		if ($saved === null) {
			return new JSONResponse(['error' => 'save_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['pages' => $this->pages->listFor(account: $saved)]);
	}//end update()

	/**
	 * The refusal for a request without a session or without the staff
	 * action, or null when the signed-in user may manage accounts.
	 *
	 * @return JSONResponse|null
	 */
	private function refusal(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: StaffAction::PROVISION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusal()
}//end class
