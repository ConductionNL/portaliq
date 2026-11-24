<?php

/**
 * Portaliq Submission Admin Controller
 *
 * The staff side of the proof-of-receipt log: an employee registers a failed
 * or pending receipt again. Only the staff group that reads `portalSubmission`
 * may call it, and that check runs before any record is read.
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
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-register-a-failed-receipt-again
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\AdminMenuAccess;
use OCA\Portaliq\Service\StaffSubmissionReader;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Registers a submission's receipt again.
 *
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-register-a-failed-receipt-again
 */
class SubmissionAdminController extends Controller {


	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request     The request.
	 * @param IUserSession             $userSession The signed-in user.
	 * @param IGroupManager            $groups      Staff group membership.
	 * @param StaffSubmissionReader    $reader      Reads the record as the caller.
	 * @param SubmissionReceiptService $receipts    Writes the receipt again.
	 * @param IL10N                    $l10n        Translates the answer.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly StaffSubmissionReader $reader,
		private readonly SubmissionReceiptService $receipts,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()


	/**
	 * Register the receipt of one failed or pending submission again.
	 *
	 * 401 without a user, 403 outside the staff group (before any read), 404
	 * for a record the caller cannot read, 409 for a delivered record, 502
	 * when the receipt could not be written, else 200.
	 *
	 * @param string $id The submission.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-register-a-failed-receipt-again
	 */
	#[NoAdminRequired]
	public function registerAgain(string $id): JSONResponse {
		$refusal = $this->requireStaff();
		if ($refusal !== null) {
			return $refusal;
		}

		$record = $this->reader->find(id: $id);
		if ($record === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if (($record['deliveryStatus'] ?? '') === 'delivered') {
			return new JSONResponse(['error' => 'already_delivered'], Http::STATUS_CONFLICT);
		}

		if ($this->receipts->registerAgain(record: $record) === false) {
			return new JSONResponse(
				['error' => 'not_registered', 'deliveryStatus' => 'failed', 'message' => $this->l10n->t('The receipt could not be registered again')],
				Http::STATUS_BAD_GATEWAY
			);
		}

		return new JSONResponse(['deliveryStatus' => 'delivered', 'message' => $this->l10n->t('The receipt is registered again')]);
	}//end registerAgain()


	/**
	 * Refuse anyone who is not an administrator or in the staff group.
	 *
	 * @return JSONResponse|null The refusal, or null for staff.
	 */
	private function requireStaff(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$uid = $user->getUID();
		if ($this->groups->isAdmin($uid) === true || $this->groups->isInGroup($uid, AdminMenuAccess::STAFF_GROUP) === true) {
			return null;
		}

		return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
	}//end requireStaff()
}//end class
