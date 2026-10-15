<?php

/**
 * MessageGuardianControllerTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
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

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MessageGuardianController;
use OCA\Portaliq\Service\Identity\PortalContactService;
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\Messaging\GuardianMessageTranslator;
use OCA\Portaliq\Service\Messaging\GuardianMessagingLeafInterface;
use OCA\Portaliq\Service\Messaging\MessageContactReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/guardian-direct-messages/design.md#api-design
 */
class MessageGuardianControllerTest extends TestCase {

	private function controller(?array $subject, ?GuardianMessagingLeafInterface $messaging = null): MessageGuardianController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new MessageGuardianController($request, $session, $messaging ?? $this->createMock(GuardianMessagingLeafInterface::class));
	}//end controller()

	public function testCreateThreadFailsClosedWithoutAResolvedSubject(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->expects($this->never())->method('createThread');

		$response = $this->controller(null, $messaging)->createThread('staff-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testCreateThreadFailsClosedWithoutAResolvedSubject()

	public function testCreateThreadReturns403WhenRefused(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('createThread')->willReturn(null);

		$response = $this->controller(['subjectRef' => 'guardian-1'], $messaging)->createThread('staff-outsider');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testCreateThreadReturns403WhenRefused()

	public function testCreateThreadReturnsTheIdOnSuccess(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('createThread')->willReturn('thread-1');

		$response = $this->controller(['subjectRef' => 'guardian-1'], $messaging)->createThread('staff-5a');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('thread-1', $response->getData()['id']);
	}//end testCreateThreadReturnsTheIdOnSuccess()

	public function testMessagesReturns404ForANonParticipantThread(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('listMessages')->willReturn(null);

		$response = $this->controller(['subjectRef' => 'guardian-1'], $messaging)->messages('thread-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testMessagesReturns404ForANonParticipantThread()

	public function testPostReturns204OnSuccess(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('postMessage')->willReturn(true);

		$response = $this->controller(['subjectRef' => 'guardian-1'], $messaging)->post('thread-1', 'hi');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testPostReturns204OnSuccess()

	public function testMarkReadReturns404WhenRefused(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('markThreadRead')->willReturn(false);

		$response = $this->controller(['subjectRef' => 'guardian-1'], $messaging)->markRead('thread-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testMarkReadReturns404WhenRefused()
	/**
	 * translated-message-notice: the controller over a translator and the
	 * reader's own preference.
	 *
	 * @param string $language The reader's messageLanguage.
	 * @param GuardianMessageTranslator $translator The translator double.
	 *
	 * @return MessageGuardianController
	 */
	private function translatingController(string $language, GuardianMessageTranslator $translator): MessageGuardianController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(['subjectRef' => 'guardian-1']);

		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('listMessages')->willReturn([['id' => 'm1', 'senderRef' => 'teacher-1', 'body' => 'De school is morgen dicht.']]);

		$selfService = $this->getMockBuilder(PortalSelfServiceService::class)
			->disableOriginalConstructor()
			->onlyMethods(['messageLanguage'])
			->getMock();
		$selfService->method('messageLanguage')->with('guardian-1')->willReturn($language);

		return new MessageGuardianController($request, $session, $messaging, $translator, $selfService);
	}//end translatingController()

	/**
	 * With a language picked, the reader gets the translator's rows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-the-stored-message-keeps-both-texts-and-the-provenance
	 */
	public function testMessagesComeBackInTheReadersLanguage(): void {
		$translated = [['id' => 'm1', 'senderRef' => 'teacher-1', 'body' => 'De school is morgen dicht.', 'translation' => ['targetLanguage' => 'tr', 'text' => 'Okul yarın kapalı.', 'translatedByAi' => true]]];
		$translator = $this->createMock(GuardianMessageTranslator::class);
		$translator->expects($this->once())
			->method('forReader')
			->with($this->anything(), 'guardian-1', 'tr')
			->willReturn($translated);

		$response = $this->translatingController('tr', $translator)->messages('thread-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($translated, $response->getData());
	}

	/**
	 * Without a language, the translator is never asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-translation-work-per-request-is-bounded-and-skips-what-needs-none
	 */
	public function testMessagesStayAsWrittenWithoutALanguage(): void {
		$translator = $this->createMock(GuardianMessageTranslator::class);
		$translator->expects($this->never())->method('forReader');

		$response = $this->translatingController('', $translator)->messages('thread-1');

		$this->assertSame('De school is morgen dicht.', $response->getData()[0]['body']);
		$this->assertArrayNotHasKey('translation', $response->getData()[0]);
	}

	private function contactsController(?array $subject, ?MessageContactReader $contacts): MessageGuardianController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new MessageGuardianController($request, $session, $this->createMock(GuardianMessagingLeafInterface::class), null, null, $contacts);
	}//end contactsController()

	public function testContactsFailsClosedWithoutAResolvedSubject(): void {
		$contacts = $this->createMock(MessageContactReader::class);
		$contacts->expects($this->never())->method('contactsFor');

		$response = $this->contactsController(null, $contacts)->contacts();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'unauthorized'], $response->getData());
	}//end testContactsFailsClosedWithoutAResolvedSubject()

	public function testContactsIsEmptyWithoutAContactReader(): void {
		$response = $this->contactsController(['subjectRef' => 'resident-1'], null)->contacts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['composeLabel' => '', 'composeHint' => '', 'contacts' => []], $response->getData());
	}//end testContactsIsEmptyWithoutAContactReader()

	public function testContactsReturnsWhatTheReaderNamesForTheSubject(): void {
		$subject  = ['subjectRef' => 'resident-1'];
		$expected = ['composeLabel' => 'Schrijf de school', 'composeHint' => 'Kies de leerkracht', 'contacts' => [['staffRef' => 'staff-1', 'recordRef' => 'child-1']]];

		$contacts = $this->createMock(MessageContactReader::class);
		$contacts->expects($this->once())->method('contactsFor')->with($subject)->willReturn($expected);

		$response = $this->contactsController($subject, $contacts)->contacts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($expected, $response->getData());
	}//end testContactsReturnsWhatTheReaderNamesForTheSubject()

	/**
	 * A controller with the resident's own contacts: `c-1` is an approved
	 * contact of `guardian-1` (subjectRef `res-2`, "Jan de Vries").
	 *
	 * @param GuardianMessagingLeafInterface $messaging The leaf.
	 *
	 * @return MessageGuardianController
	 */
	private function residentContactsController(GuardianMessagingLeafInterface $messaging): MessageGuardianController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(['subjectRef' => 'guardian-1', 'organisation' => 'org-1']);
		$contacts = $this->getMockBuilder(PortalContactService::class)
			->disableOriginalConstructor()
			->onlyMethods(['approvedContact', 'contactNames'])
			->getMock();
		$contacts->method('approvedContact')->willReturnCallback(
			static fn (string $owner, string $organisation, string $id): ?array => ($owner === 'guardian-1' && $organisation === 'org-1' && $id === 'c-1')
				? ['id' => 'c-1', 'ref' => 'res-2', 'displayName' => 'Jan de Vries'] : null
		);
		$contacts->method('contactNames')->willReturn(['res-2' => 'Jan de Vries']);

		return new MessageGuardianController($request, $session, $messaging, null, null, null, $contacts);
	}//end residentContactsController()

	/**
	 * T07: "Bericht sturen" starts a conversation with an approved contact,
	 * proven on the server from the resident's own row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t07
	 */
	public function testAConversationStartsWithAnApprovedContact(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->expects($this->once())
			->method('createContactThread')
			->with('guardian-1', ['staffRef' => 'res-2', 'name' => 'Jan de Vries', 'role' => '', 'recordRef' => '', 'recordLabel' => ''], 'Kijk je mee?', 'Kijk je mee?')
			->willReturn('thread-9');

		$response = $this->residentContactsController($messaging)->createThread(contactId: 'c-1', body: 'Kijk je mee?');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'thread-9'], $response->getData());
	}//end testAConversationStartsWithAnApprovedContact()

	/**
	 * T07: a contact row that is not the resident's own approved one is
	 * refused, and an empty message is refused before anything is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t07
	 */
	public function testAConversationWithAnyoneElseIsRefused(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->expects($this->never())->method('createContactThread');
		$messaging->expects($this->never())->method('createThread');
		$controller = $this->residentContactsController($messaging);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->createThread(contactId: 'someone-elses', body: 'Hallo')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->createThread(staffRef: 'res-2', contactId: 'pending-row', body: 'Hallo')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->createThread(contactId: 'c-1', body: '  ')->getStatus());
	}//end testAConversationWithAnyoneElseIsRefused()

	/**
	 * T07: each side of a conversation between residents reads it under the
	 * other person's name; a school conversation keeps its own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t07
	 */
	public function testAResidentConversationShowsTheOtherPersonsName(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('listThreads')->willReturn([
			['id' => 't1', 'kind' => 'direct', 'participantRefs' => ['res-2', 'guardian-1'], 'staffName' => 'Guardian One', 'recordRef' => ''],
			['id' => 't2', 'kind' => 'direct', 'participantRefs' => ['guardian-1', 'teacher-1'], 'staffName' => 'Meester Daan', 'staffRole' => 'Leerkracht', 'recordRef' => 'kid-1'],
		]);
		$messaging->method('listMessages')->willReturn([]);

		$threads = $this->residentContactsController($messaging)->threads()->getData();

		$this->assertSame('Jan de Vries', $threads[0]['staffName']);
		$this->assertSame('Meester Daan', $threads[1]['staffName']);
		$this->assertSame('Leerkracht', $threads[1]['staffRole']);
	}//end testAResidentConversationShowsTheOtherPersonsName()
}//end class
