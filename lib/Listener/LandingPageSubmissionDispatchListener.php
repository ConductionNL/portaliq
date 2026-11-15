<?php

/**
 * Portaliq LandingPageSubmissionDispatchListener
 *
 * Relays a `landingPageSubmission` write to the contributing app that
 * requested the originating form, via ADR-041 typed dispatch
 * (`LandingPageFormSubmittedEvent`). Fail-SAFE, not fail-closed (see the
 * class docblock on the event itself and design.md Decision 2 / Risk 1): a
 * visitor's already-durable submission is never turned into an error because
 * the consumer app is not installed, or has not shipped its own event class
 * yet.
 *
 * Reacts to OpenRegister's native `ObjectCreatedEvent` — the same pattern
 * `CmsCacheInvalidationListener` already establishes in this app — rather
 * than adding a dependency to `ContributionController`'s constructor, so the
 * controller's existing, carefully audited authorisation logic stays
 * untouched by this change.
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
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
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves and dispatches the contributing app's own
 * `LandingPageFormSubmittedEvent` after a `landingPageSubmission` write.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
 */
class LandingPageSubmissionDispatchListener implements IEventListener {

	/**
	 * The register this listener watches.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema this listener watches.
	 */
	private const SCHEMA = 'landingPageSubmission';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Dispatches the resolved consumer event.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister object write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-submission-is-relayed-to-the-contributing-app-as-a-fail-safe-not-a-fail-closed-cross-app-event
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		try {
			$this->relay(event: $event);
		} catch (Throwable $e) {
			// See class docblock: a relay failure NEVER surfaces to the
			// visitor whose submission already succeeded.
			$this->logger->warning(
				'Portaliq: landing page submission relay failed unexpectedly',
				['reason' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Filter to `landingPageSubmission` writes and relay them.
	 *
	 * @param ObjectCreatedEvent $event The OpenRegister create event.
	 *
	 * @return void
	 */
	private function relay(ObjectCreatedEvent $event): void {
		$data = $this->submissionOf(event: $event);
		if ($data === null) {
			return;
		}

		$eventClass = $this->consumerEventClassFor(data: $data);
		if ($eventClass === null) {
			return;
		}

		$this->dispatcher->dispatchTyped(new $eventClass(...self::eventArguments(data: $data)));
	}//end relay()

	/**
	 * The data of a `landingPageSubmission` write, or null for any other write.
	 *
	 * @param ObjectCreatedEvent $event The OpenRegister create event.
	 *
	 * @return array<string, mixed>|null
	 */
	private function submissionOf(ObjectCreatedEvent $event): ?array {
		$entity = $event->getObject();
		if ($entity->getRegister() !== self::REGISTER || $entity->getSchema() !== self::SCHEMA) {
			return null;
		}

		$data = $entity->getObject();
		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end submissionOf()

	/**
	 * The consumer event class for a submission's `sourceApp`, or null (logged)
	 * when it names none or its app has no such event.
	 *
	 * @param array<string, mixed> $data The submission.
	 *
	 * @return class-string|null
	 */
	private function consumerEventClassFor(array $data): ?string {
		$sourceApp = trim((string)($data['sourceApp'] ?? ''));
		if ($sourceApp === '') {
			$this->logger->warning('Portaliq: landing page submission has no sourceApp, cannot relay');
			return null;
		}

		$eventClass = $this->resolveConsumerEventClass(sourceApp: $sourceApp);
		if ($eventClass === null) {
			// Consumer app not installed, or has not shipped its own event
			// class yet (e.g. pipelinq before its phase 4 change lands) —
			// this is the expected, non-exceptional case this change ships
			// ahead of any consumer.
			$this->logger->info(
				'Portaliq: no landing page submission consumer registered yet',
				['sourceApp' => $sourceApp]
			);
		}

		return $eventClass;
	}//end consumerEventClassFor()

	/**
	 * The consumer event's constructor arguments, in its order; the external
	 * reference doubles as the correlation id.
	 *
	 * @param array<string, mixed> $data The submission.
	 *
	 * @return list<mixed>
	 */
	private static function eventArguments(array $data): array {
		$text = static fn (string $key): string => (string)($data[$key] ?? '');
		$list = static fn (string $key): array => (array)($data[$key] ?? []);

		return [
			trim($text('sourceApp')), $text('formId'), $text('pageId'), $text('pageRoute'), $text('portal'),
			$text('externalReference'), $list('values'), $list('utmFirstTouch'), $list('utmLastTouch'),
			$text('referrer'), $text('submittedAt'), $text('nonce'), $text('externalReference'),
		];
	}//end eventArguments()

	/**
	 * Resolve the consumer app's `LandingPageFormSubmittedEvent` FQCN by its
	 * `sourceApp`, or null when it does not exist. Fleet app ids are single
	 * lowercase words (per the iq-rename convention) whose PHP namespace is
	 * `ucfirst($id)` — this covers every app in the fleet's scope of record
	 * today. An app whose namespace genuinely diverges from `ucfirst($id)`
	 * would need a small explicit override added here.
	 *
	 * @param string $sourceApp The app id recorded on the submission.
	 *
	 * @return class-string|null
	 */
	private function resolveConsumerEventClass(string $sourceApp): ?string {
		$candidate = '\\OCA\\' . ucfirst($sourceApp) . '\\Event\\LandingPageFormSubmittedEvent';
		if (class_exists($candidate) === true) {
			return $candidate;
		}

		return null;
	}//end resolveConsumerEventClass()
}//end class
