<?php

/**
 * LandingPageFormSubmittedEventTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Event;

use OCA\Portaliq\Event\LandingPageFormSubmittedEvent;
use OCA\Portaliq\Listener\LandingPageSubmissionDispatchListener;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The submission event as the relay builds it.
 *
 * LandingPageSubmissionDispatchListener resolves the event class by
 * `sourceApp` and constructs it from a positional argument list, so a field
 * added to the constructor in a different place than the relay puts it would
 * silently shift every value after it. These tests build the event the way the
 * relay does and read every field back, then exercise the two result slots a
 * consuming handler writes.
 *
 * @covers \OCA\Portaliq\Event\LandingPageFormSubmittedEvent
 * @uses   \OCA\Portaliq\Listener\LandingPageSubmissionDispatchListener
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
 */
class LandingPageFormSubmittedEventTest extends TestCase {

	/**
	 * A stored landingPageSubmission object.
	 *
	 * @var array<string, mixed>
	 */
	private const SUBMISSION = [
		'sourceApp' => ' portaliq ',
		'formId' => 'form-1',
		'pageId' => 'page-1',
		'pageRoute' => '/campagne/x',
		'portal' => 'open-tilburg',
		'externalReference' => 'ext-1',
		'values' => ['email' => 'jane@example.org'],
		'utmFirstTouch' => ['campaign' => 'a'],
		'utmLastTouch' => ['campaign' => 'b'],
		'referrer' => 'https://example.org/',
		'submittedAt' => '2026-09-04T12:00:00+00:00',
		'nonce' => 'nonce-1',
	];

	/**
	 * Build the event exactly as the relay does for a stored submission.
	 *
	 * @param array<string, mixed> $data The stored submission.
	 *
	 * @return LandingPageFormSubmittedEvent
	 */
	private function relayed(array $data): LandingPageFormSubmittedEvent {
		$arguments = (new ReflectionMethod(LandingPageSubmissionDispatchListener::class, 'eventArguments'))
			->invoke(null, $data);

		return new LandingPageFormSubmittedEvent(...$arguments);
	}//end relayed()

	/**
	 * Portaliq itself answers to `sourceApp: portaliq`: the relay resolves this
	 * class, not a consumer's.
	 *
	 * @return void
	 */
	public function testTheRelayResolvesThisClassForPortaliqItself(): void {
		$listener = (new \ReflectionClass(LandingPageSubmissionDispatchListener::class))->newInstanceWithoutConstructor();
		$resolve = new ReflectionMethod(LandingPageSubmissionDispatchListener::class, 'resolveConsumerEventClass');

		$this->assertSame(
			'\\' . LandingPageFormSubmittedEvent::class,
			$resolve->invoke($listener, 'portaliq')
		);
	}//end testTheRelayResolvesThisClassForPortaliqItself()

	/**
	 * Every stored field lands in its own getter, the source app trimmed and
	 * the external reference doubling as the correlation id.
	 *
	 * @return void
	 */
	public function testEveryFieldTheRelayPassesLandsInItsOwnGetter(): void {
		$event = $this->relayed(data: self::SUBMISSION);

		$this->assertSame('portaliq', $event->getSourceApp());
		$this->assertSame('form-1', $event->getFormId());
		$this->assertSame('page-1', $event->getPageId());
		$this->assertSame('/campagne/x', $event->getPageRoute());
		$this->assertSame('open-tilburg', $event->getPortal());
		$this->assertSame('ext-1', $event->getExternalReference());
		$this->assertSame(['email' => 'jane@example.org'], $event->getValues());
		$this->assertSame(['campaign' => 'a'], $event->getUtmFirstTouch());
		$this->assertSame(['campaign' => 'b'], $event->getUtmLastTouch());
		$this->assertSame('https://example.org/', $event->getReferrer());
		$this->assertSame('2026-09-04T12:00:00+00:00', $event->getSubmittedAt());
		$this->assertSame('nonce-1', $event->getNonce());
		$this->assertSame('ext-1', $event->getCorrelationId());
	}//end testEveryFieldTheRelayPassesLandsInItsOwnGetter()

	/**
	 * A sparse submission still builds: missing text is empty, missing lists
	 * are empty arrays.
	 *
	 * @return void
	 */
	public function testASparseSubmissionBuildsWithEmptyFields(): void {
		$event = $this->relayed(data: ['sourceApp' => 'portaliq']);

		$this->assertSame('', $event->getFormId());
		$this->assertSame([], $event->getValues());
		$this->assertSame([], $event->getUtmLastTouch());
		$this->assertSame('', $event->getCorrelationId());
	}//end testASparseSubmissionBuildsWithEmptyFields()

	/**
	 * Built without a correlation id, the event carries an empty one.
	 *
	 * @return void
	 */
	public function testTheCorrelationIdDefaultsToEmpty(): void {
		$event = new LandingPageFormSubmittedEvent('portaliq', 'f', 'p', '/r', 'portal', 'ext', [], [], [], '', 'now', 'n');

		$this->assertSame('', $event->getCorrelationId());
	}//end testTheCorrelationIdDefaultsToEmpty()

	/**
	 * A fresh event is unhandled and names no lead; a consuming handler claims
	 * it by writing both result slots.
	 *
	 * @return void
	 */
	public function testAHandlerClaimsTheSubmissionThroughTheResultSlots(): void {
		$event = $this->relayed(data: self::SUBMISSION);

		$this->assertNull($event->getLeadId());
		$this->assertFalse($event->isHandled());

		$event->setLeadId('lead-42');
		$event->setHandled(true);

		$this->assertSame('lead-42', $event->getLeadId());
		$this->assertTrue($event->isHandled());

		$event->setHandled(false);
		$this->assertFalse($event->isHandled());
	}//end testAHandlerClaimsTheSubmissionThroughTheResultSlots()
}//end class
