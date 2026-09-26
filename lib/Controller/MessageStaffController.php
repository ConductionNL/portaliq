<?php

/**
 * Message Staff Controller
 *
 * The minimal staff-side reply capability for `guardian-direct-messages`:
 * create a group thread for a group the caller teaches, list own threads,
 * read/post/mark-read. Requires a Nextcloud session (`#[NoAdminRequired]`),
 * same posture as `NewsController`/`EventController`. The full per-group
 * INBOX aggregation view is `teacher-inbox-per-group`, a separate, stacked
 * change — this controller is deliberately just the reply/create primitive.
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
 * @spec openspec/changes/guardian-direct-messages/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Messaging\GuardianMessagingLeafInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Staff read/create/post/mark-read path for message threads.
 *
 * @spec openspec/changes/guardian-direct-messages/design.md#api-design
 */
class MessageStaffController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Resolves the calling staff member's Nextcloud user id, used as their subjectRef.
	 * @param GuardianMessagingLeafInterface $messaging The messaging leaf.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly GuardianMessagingLeafInterface $messaging,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create a group thread for a group the caller teaches.
	 *
	 * @param string $groupRef The group.
	 *
	 * @return JSONResponse `{id}` on success, 403 on refusal.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-group-thread-reaches-every-in-audience-guardian-replies-are-two-way
	 */
	#[NoAdminRequired]
	public function createGroupThread(string $groupRef): JSONResponse {
		$staffRef = $this->staffRef();
		$id = $this->messaging->createThread(kind: 'group', participantRefs: [], groupRef: $groupRef, createdBy: $staffRef);
		if ($id === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['id' => $id]);
	}//end createGroupThread()

	/**
	 * Every thread the staff member participates in.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#api-design
	 */
	#[NoAdminRequired]
	public function threads(): JSONResponse {
		return new JSONResponse($this->messaging->listThreads(subjectRef: $this->staffRef(), isStaff: true));
	}//end threads()

	/**
	 * Every message in a thread the staff member participates in.
	 *
	 * @param string $id The thread id.
	 *
	 * @return JSONResponse The messages, or 404.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 */
	#[NoAdminRequired]
	public function messages(string $id): JSONResponse {
		$messages = $this->messaging->listMessages(threadId: $id, subjectRef: $this->staffRef(), isStaff: true);
		if ($messages === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($messages);
	}//end messages()

	/**
	 * Post a reply into a thread the staff member participates in.
	 *
	 * @param string $id The thread id.
	 * @param string $body The message body.
	 *
	 * @return JSONResponse 204 on success, 404 otherwise.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 */
	#[NoAdminRequired]
	public function post(string $id, string $body): JSONResponse {
		$posted = $this->messaging->postMessage(threadId: $id, senderRef: $this->staffRef(), senderIsStaff: true, body: $body);
		if ($posted === false) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end post()

	/**
	 * Mark a thread's messages read for the staff member.
	 *
	 * @param string $id The thread id.
	 *
	 * @return JSONResponse 204 on success, 404 otherwise.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-message-tracks-who-has-read-it
	 */
	#[NoAdminRequired]
	public function markRead(string $id): JSONResponse {
		$marked = $this->messaging->markThreadRead(threadId: $id, subjectRef: $this->staffRef(), isStaff: true);
		if ($marked === false) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end markRead()

	/**
	 * The calling staff member's own subjectRef — their Nextcloud user id.
	 * `#[NoAdminRequired]` guarantees a signed-in user reaches these methods,
	 * so a null user here would be an OCP framework/session inconsistency,
	 * not a normal-flow input to guard.
	 *
	 * @return string
	 */
	private function staffRef(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end staffRef()
}//end class
