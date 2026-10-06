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
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\Messaging\GuardianMessageTranslator;
use OCA\Portaliq\Service\Messaging\GuardianMessagingLeafInterface;
use OCA\Portaliq\Service\Messaging\MessageContactReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
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
	 * The longest first message.
	 */
	private const MAX_BODY = 5000;

	/**
	 * The longest subject line.
	 */
	private const MAX_TITLE = 120;

	/**
	 * How much of the message a missing subject line takes.
	 */
	private const DEFAULT_TITLE_WIDTH = 60;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param GuardianMessagingLeafInterface $messaging The messaging leaf.
	 * @param GuardianMessageTranslator|null $translator Shows messages in the reader's
	 *                                                   language (translated-message-notice).
	 *                                                   Nullable and trailing so a
	 *                                                   controller built by hand keeps
	 *                                                   its old shape.
	 * @param PortalSelfServiceService|null $selfService Reads the reader's own `messageLanguage`.
	 * @param MessageContactReader|null $contacts Who the resident may write to, per record
	 *                                            (site-messages-per-record).
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly GuardianMessagingLeafInterface $messaging,
		private readonly ?GuardianMessageTranslator $translator = null,
		private readonly ?PortalSelfServiceService $selfService = null,
		private readonly ?MessageContactReader $contacts = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Start a direct thread with a staff member — refused unless that staff
	 * member teaches a group the guardian's own audience reaches. With a
	 * `recordRef` the staff member must instead be a contact the app names
	 * for that record of the resident (site-messages-per-record), and the
	 * thread starts with its first message.
	 *
	 * @param string $staffRef  The staff member's subjectRef.
	 * @param string $recordRef The record the conversation is about ('' for the older rule).
	 * @param string $title     The subject line.
	 * @param string $body      The first message.
	 *
	 * @return JSONResponse `{id}` on success, 400 or 403 on refusal.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function createThread(string $staffRef, string $recordRef = '', string $title = '', string $body = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if ($recordRef !== '') {
			return $this->createContactThread(subject: $subject, staffRef: $staffRef, recordRef: $recordRef, title: $title, body: $body);
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
	#[AnonRateLimit(limit: 60, period: 60)]
	public function threads(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$subjectRef = (string)($subject['subjectRef'] ?? '');
		$threads    = [];
		foreach ($this->messaging->listThreads(subjectRef: $subjectRef, isStaff: false) as $thread) {
			$threads[] = $thread + ['summary' => $this->summaryOf(thread: $thread, subjectRef: $subjectRef)];
		}

		return new JSONResponse($threads);
	}//end threads()

	/**
	 * Who the resident may write to, per record they own, and the form's own
	 * words (site-messages-per-record).
	 *
	 * @return JSONResponse `{composeLabel, composeHint, contacts}`, or 401.
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function contacts(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->contacts === null) {
			return new JSONResponse(['composeLabel' => '', 'composeHint' => '', 'contacts' => []]);
		}

		return new JSONResponse($this->contacts->contactsFor(subject: $subject));
	}//end contacts()

	/**
	 * A new conversation about one record: the contact is proven again on the
	 * server, never taken from the browser, then the thread and its first
	 * message are stored together.
	 *
	 * @param array<string, mixed> $subject   The resolved subject.
	 * @param string               $staffRef  The person written to.
	 * @param string               $recordRef The record it is about.
	 * @param string               $title     The subject line; the start of the message when empty.
	 * @param string               $body      The first message.
	 *
	 * @return JSONResponse `{id}`, or 400 / 403.
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
	 */
	private function createContactThread(array $subject, string $staffRef, string $recordRef, string $title, string $body): JSONResponse {
		$body  = trim($body);
		$title = trim($title);
		if ($body === '' || mb_strlen($body) > self::MAX_BODY || mb_strlen($title) > self::MAX_TITLE) {
			return new JSONResponse(['error' => 'invalid'], Http::STATUS_BAD_REQUEST);
		}

		$contact = $this->contacts?->contactFor(subject: $subject, staffRef: $staffRef, recordRef: $recordRef);
		if ($contact === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($title === '') {
			$title = mb_strimwidth(preg_replace('/\s+/', ' ', $body) ?? $body, 0, self::DEFAULT_TITLE_WIDTH, '…');
		}

		$id = $this->messaging->createContactThread(subjectRef: (string)($subject['subjectRef'] ?? ''), contact: $contact, title: $title, body: $body);
		if ($id === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['id' => $id]);
	}//end createContactThread()

	/**
	 * A thread's newest message and how many the reader has not read yet,
	 * so the list can say "Nieuw" and show the start of the last message.
	 *
	 * @param array<string, mixed> $thread     The thread.
	 * @param string               $subjectRef The reader.
	 *
	 * @return array{unread: int, lastBody: string, lastSentAt: string, lastFromMe: bool}
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-the-messages-page-groups-conversations-per-record-and-lets-a-resident-write-and-reply
	 */
	private function summaryOf(array $thread, string $subjectRef): array {
		$summary = ['unread' => 0, 'lastBody' => '', 'lastSentAt' => '', 'lastFromMe' => false];
		$id      = (string)($thread['id'] ?? ($thread['uuid'] ?? ($thread['@self']['id'] ?? '')));
		foreach (($this->messaging->listMessages(threadId: $id, subjectRef: $subjectRef, isStaff: false) ?? []) as $message) {
			$fromMe = ((string)($message['senderRef'] ?? '') === $subjectRef);
			if ($fromMe === false && in_array($subjectRef, (array)($message['readBy'] ?? []), true) === false) {
				$summary['unread']++;
			}

			$sentAt = (string)($message['sentAt'] ?? '');
			if ($sentAt >= $summary['lastSentAt']) {
				$summary['lastBody']   = (string)($message['body'] ?? '');
				$summary['lastSentAt'] = $sentAt;
				$summary['lastFromMe'] = $fromMe;
			}
		}

		return $summary;
	}//end summaryOf()

	/**
	 * Every message in a thread the guardian participates in.
	 *
	 * @param string $id The thread id.
	 *
	 * @return JSONResponse The messages, or 404. A message translated into the
	 *                      reader's `messageLanguage` carries `translation`.
	 *
	 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-every-readpostmark-read-re-verifies-participation-server-side
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-the-stored-message-keeps-both-texts-and-the-provenance
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function messages(string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$subjectRef = (string)($subject['subjectRef'] ?? '');
		$messages   = $this->messaging->listMessages(threadId: $id, subjectRef: $subjectRef, isStaff: false);
		if ($messages === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->inReadersLanguage(messages: $messages, subjectRef: $subjectRef));
	}//end messages()

	/**
	 * The messages in the reader's own language, when they picked one. Runs
	 * only after participation was verified, on messages already authorised.
	 *
	 * @param array<int, array<string, mixed>> $messages The authorised messages.
	 * @param string $subjectRef The reader.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-translation-work-per-request-is-bounded-and-skips-what-needs-none
	 */
	private function inReadersLanguage(array $messages, string $subjectRef): array {
		if ($this->translator === null || $this->selfService === null || $subjectRef === '') {
			return $messages;
		}

		$language = $this->selfService->messageLanguage(subjectRef: $subjectRef);
		if ($language === '') {
			return $messages;
		}

		return $this->translator->forReader(messages: $messages, readerRef: $subjectRef, language: $language);
	}//end inReadersLanguage()

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
	#[AnonRateLimit(limit: 20, period: 60)]
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
	#[AnonRateLimit(limit: 20, period: 60)]
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
