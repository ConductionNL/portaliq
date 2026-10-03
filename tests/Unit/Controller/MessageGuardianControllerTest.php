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
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\Messaging\GuardianMessageTranslator;
use OCA\Portaliq\Service\Messaging\GuardianMessagingLeafInterface;
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
}//end class
