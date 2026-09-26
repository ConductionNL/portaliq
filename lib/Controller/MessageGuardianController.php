<?php

/**
 * Message Guardian Controller
 *
 * The guardian-facing side of `guardian-direct-messages`: start a direct
 * thread with a reachable teacher, list own threads, read/post/mark-read —
 * all scoped to the CALLING guardian's own participation. Guarded by
 * `PortalAuthMiddleware` via the `PortalProtected` marker (fail-closed 401
 * without a valid bearer); the subject is read from the validated bearer,
 * never from a client parameter.
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
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\Messaging\GuardianMessagingLeafInterface;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Guardian read/create/post/mark-read path for message threads.
 *
 * @spec openspec/changes/guardian-direct-messages/design.md#api-design
 */
class MessageGuardianController extends Controller implements PortalProtected {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param GuardianMessagingLeafInterface $messaging The messaging leaf.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly GuardianMessagingLeafInterface $messaging,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Start a direct thread with a staff member — refused unless that staff
	 * member teaches a group the guardian's own audience reaches.
	 *
	 * @param string $staffRef The staff member's subjectRef.
	 *
	 * @return JSONResponse `{id}` on success, 403 on refusal.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function createThread(string $staffRef): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$subjectRef = (string)($subject['subjectRef'] ?? '');
		$id = $this->messaging->createThread(kind: 'direct', participantRefs: [$subjectRef, $staffRef], groupRef: null, createdBy: $subjectRef);
		if ($id === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['id' => $id]);
	}//end createThread()

	/**
	 * Every thread the guardian participates in.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/guardian-direct-messages/design.md#api-design
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function threads(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->messaging->listThreads(subjectRef: (string)($subject['subjectRef'] ?? ''), isStaff: false));
	}//end threads()

	/**
	 * Every message in a thread the guardian participates in.
	 *
	 * @param string $id The thread id.
	 *
	 * @return JSONResponse The messages, or 404.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function messages(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$messages = $this->messaging->listMessages(threadId: $id, subjectRef: (string)($subject['subjectRef'] ?? ''), isStaff: false);
		if ($messages === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($messages);
	}//end messages()

	/**
	 * Post a message into a thread the guardian participates in.
	 *
	 * @param string $id The thread id.
	 * @param string $body The message body.
	 *
	 * @return JSONResponse 204 on success, 404 otherwise.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function post(string $id, string $body): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$posted = $this->messaging->postMessage(threadId: $id, senderRef: (string)($subject['subjectRef'] ?? ''), senderIsStaff: false, body: $body);
		if ($posted === false) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end post()

	/**
	 * Mark a thread's messages read for the guardian.
	 *
	 * @param string $id The thread id.
	 *
	 * @return JSONResponse 204 on success, 404 otherwise.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-message-tracks-who-has-read-it
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function markRead(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$marked = $this->messaging->markThreadRead(threadId: $id, subjectRef: (string)($subject['subjectRef'] ?? ''), isStaff: false);
		if ($marked === false) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end markRead()

	/**
	 * Resolve the subject from the bearer (fail-closed).
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()
}//end class
