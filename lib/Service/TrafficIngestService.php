<?php

/**
 * Portaliq Traffic Ingest Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-an-ip-address-must-not-be-stored
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\Traffic\GeoResolverInterface;
use OCA\Portaliq\Service\Traffic\ReferrerClassifier;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficMetrics;
use OCA\Portaliq\Service\Traffic\UserAgentClassifier;
use OCA\Portaliq\Service\Traffic\VisitorHasher;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Turns a batch of posted events into stored records, for one portal.
 *
 * THIS IS THE ONE PLACE THE ADDRESS AND THE USER AGENT EXIST. They arrive in
 * `$context`, they are read to derive a visitor hash, a device family and
 * (in a later phase) a region, and they are not written anywhere: not to
 * the record, not to the log, not to the metrics. Every derived value is
 * only KEPT when the portal enabled its dimension.
 *
 * Two callers: the HTTP collector, which resolved the portal by host, and
 * other apps in this instance (pipelinq's mail events), which name a portal
 * by slug and say they are server-side.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-an-ip-address-must-not-be-stored
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the ingest step is
 * where the classifiers, the hasher, the store and the metrics meet; that is
 * its job, and splitting it would scatter the one privacy boundary.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the same ten
 * collaborators, injected. Bundling them into a facade would hide which
 * request-derived value comes from where, which is the thing a privacy
 * reviewer reads this file for.
 */
class TrafficIngestService {

	/**
	 * Constructor.
	 *
	 * @param PortalResolver        $portals   Finds a portal by slug.
	 * @param TrafficConfigResolver $config    Resolves a portal's measurement configuration.
	 * @param TrafficEventValidator $validator Validates one event.
	 * @param TrafficEventStore     $store     Writes the records.
	 * @param TrafficMetrics        $metrics   Counts what happened.
	 * @param VisitorHasher         $hasher    Derives the daily visitor hash.
	 * @param UserAgentClassifier   $agents    Derives device, browser, os and the bot flag.
	 * @param ReferrerClassifier    $referrers Derives host, channel, campaign and path.
	 * @param GeoResolverInterface  $geo       Derives a region, in a later phase.
	 * @param ITimeFactory          $time      The clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly TrafficConfigResolver $config,
		private readonly TrafficEventValidator $validator,
		private readonly TrafficEventStore $store,
		private readonly TrafficMetrics $metrics,
		private readonly VisitorHasher $hasher,
		private readonly UserAgentClassifier $agents,
		private readonly ReferrerClassifier $referrers,
		private readonly GeoResolverInterface $geo,
		private readonly ITimeFactory $time,
	) {
	}

	/**
	 * Ingest events for a portal named by slug.
	 *
	 * The entry point for other apps in this instance. A slug that resolves
	 * to no published portal refuses the whole batch as `unknown-portal`.
	 *
	 * @param string                           $portalSlug The portal's slug.
	 * @param array<int, array<string, mixed>> $events     The events.
	 * @param array<string, mixed>             $context    ip, userAgent, acceptLanguage, consent, serverSide.
	 *
	 * @return array{accepted: int, refused: array<string, int>} What happened.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-mail-events-must-only-be-written-server-side
	 */
	public function ingest(string $portalSlug, array $events, array $context = []): array {
		$portal = null;
		foreach ($this->portals->allPublishedPortals() as $candidate) {
			if (($candidate['slug'] ?? null) === $portalSlug) {
				$portal = $candidate;
				break;
			}
		}

		if ($portal === null) {
			return $this->refuseAll(events: $events, reason: 'unknown-portal');
		}

		return $this->ingestForPortal(portal: $portal, events: $events, context: $context);
	}

	/**
	 * Ingest events for an already resolved portal.
	 *
	 * @param array<string, mixed>             $portal  The portal record.
	 * @param array<int, array<string, mixed>> $events  The events.
	 * @param array<string, mixed>             $context ip, userAgent, acceptLanguage, consent, serverSide.
	 *
	 * @return array{accepted: int, refused: array<string, int>} What happened.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-an-ip-address-must-not-be-stored
	 */
	public function ingestForPortal(array $portal, array $events, array $context = []): array {
		$config = $this->config->resolve(portal: $portal);
		$serverSide = ($context['serverSide'] ?? false) === true;
		$agent = $this->agents->classify(userAgent: (string)($context['userAgent'] ?? ''));

		// A crawler is refused as a BATCH, counted under one reason, before
		// any event is looked at. It is not a visitor and it should not
		// inflate a page's numbers by reading every link on it.
		if ($serverSide === false && $agent['bot'] === true) {
			return $this->refuseAll(events: $events, reason: 'bot');
		}

		$now = $this->now();
		$slug = (string)($portal['slug'] ?? '');
		$hasConsent = ($context['consent'] ?? false) === true;
		$derived = $this->derivedFromRequest(agent: $agent, context: $context, config: $config);

		$records = [];
		$refused = [];
		foreach ($events as $event) {
			if (is_array($event) === false) {
				$refused['malformed-event'] = ($refused['malformed-event'] ?? 0) + 1;
				continue;
			}

			$result = $this->validator->validate(
				event: $event,
				config: $config,
				hasConsent: $hasConsent,
				resolver: $this->config,
				context: ['serverSide' => $serverSide],
				now: $now
			);
			if ($result['ok'] === false) {
				$reason = (string)($result['reason'] ?? 'refused');
				$refused[$reason] = ($refused[$reason] ?? 0) + 1;
				continue;
			}

			$records[] = $this->record(
				event: $result['event'],
				slug: $slug,
				config: $config,
				derived: $derived,
				context: $context,
				now: $now,
				hasConsent: $hasConsent
			);
		}

		$accepted = $this->store->append(records: $records);
		if ($accepted < count($records)) {
			$refused['storage-failed'] = ($refused['storage-failed'] ?? 0) + (count($records) - $accepted);
		}

		$this->metrics->accepted(count: $accepted);
		$this->metrics->refused(reasons: $refused);

		return ['accepted' => $accepted, 'refused' => $refused];
	}

	/**
	 * One stored record from one validated event.
	 *
	 * @param array<string, mixed>  $event      The validated event.
	 * @param string                $slug       The portal slug.
	 * @param array<string, mixed>  $config     The resolved configuration.
	 * @param array<string, string> $derived    Request-level derived dimensions, already filtered.
	 * @param array<string, mixed>  $context    The request context.
	 * @param DateTimeImmutable     $now        The clock.
	 * @param bool                  $hasConsent The visitor's consent state.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function record(
		array $event,
		string $slug,
		array $config,
		array $derived,
		array $context,
		DateTimeImmutable $now,
		bool $hasConsent,
	): array {
		$location = (string)$event['pageLocation'];
		$campaign = $this->referrers->campaign(location: $location);
		if (($context['serverSide'] ?? false) === true) {
			// A mail event carries its attribution in the envelope; the URL
			// it points at may carry the same, and the envelope wins.
			foreach (['campaign', 'source', 'medium', 'content', 'term'] as $key) {
				if (isset($event[$key]) === true && $event[$key] !== '') {
					$campaign[$key] = (string)$event[$key];
				}
			}
		}

		$referrerHost = $this->referrers->host(referrer: (string)($event['pageReferrer'] ?? ''));
		$pageHost = $this->referrers->host(referrer: $location);
		$perEvent = [
			'referrerHost' => $referrerHost,
			'channel' => $this->referrers->channel(referrerHost: $referrerHost, pageHost: $pageHost, campaign: $campaign),
		] + $campaign;

		$record = $event + [
			'portal' => $slug,
			'receivedAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
			'visitorHash' => $this->visitorHash(slug: $slug, event: $event, context: $context),
			'pagePath' => $this->referrers->path(location: $location),
			'consent' => $hasConsent,
			'expires' => $now->modify('+' . (int)$config['retentionDays'] . ' days')->format('Y-m-d\TH:i:s\Z'),
		] + $derived;

		foreach ($perEvent as $key => $value) {
			if ($value !== '' && in_array($key, $config['dimensions'], true) === true) {
				$record[$key] = $value;
			}
		}

		return $record;
	}

	/**
	 * The dimensions derived once per request, kept only when enabled.
	 *
	 * @param array<string, mixed> $agent   The classified user agent.
	 * @param array<string, mixed> $context The request context.
	 * @param array<string, mixed> $config  The resolved configuration.
	 *
	 * @return array<string, string> Enabled derived dimensions.
	 */
	private function derivedFromRequest(array $agent, array $context, array $config): array {
		$candidates = [
			'deviceType' => (string)$agent['deviceType'],
			'browser' => (string)$agent['browser'],
			'os' => (string)$agent['os'],
			'language' => $this->language(header: (string)($context['acceptLanguage'] ?? '')),
		];

		$granularity = (string)$config['regionGranularity'];
		if ($granularity !== 'none' && in_array('region', $config['dimensions'], true) === true) {
			$candidates['region'] = (string)($this->geo->resolve(address: (string)($context['ip'] ?? ''), granularity: $granularity) ?? '');
		}

		$out = [];
		foreach ($candidates as $key => $value) {
			if ($value !== '' && in_array($key, $config['dimensions'], true) === true) {
				$out[$key] = $value;
			}
		}

		return $out;
	}

	/**
	 * The visitor hash: address and agent for a browser, the contact
	 * reference for a mail event. Neither input is kept.
	 *
	 * @param string               $slug    The portal slug.
	 * @param array<string, mixed> $event   The validated event.
	 * @param array<string, mixed> $context The request context.
	 *
	 * @return string The hash.
	 */
	private function visitorHash(string $slug, array $event, array $context): string {
		if (($context['serverSide'] ?? false) === true) {
			return $this->hasher->hash(portal: $slug, parts: ['contact', (string)($event['contactRef'] ?? '')]);
		}

		return $this->hasher->hash(
			portal: $slug,
			parts: [(string)($context['ip'] ?? ''), (string)($context['userAgent'] ?? '')]
		);
	}

	/**
	 * The primary subtag of the first accepted language, or ''.
	 *
	 * @param string $header The Accept-Language header.
	 *
	 * @return string A two- or three-letter code, lower-cased.
	 */
	private function language(string $header): string {
		if (preg_match('/^\s*([A-Za-z]{2,3})/', $header, $match) !== 1) {
			return '';
		}

		return strtolower($match[1]);
	}

	/**
	 * Refuse a whole batch under one reason, and count it.
	 *
	 * @param array<int, mixed> $events The batch.
	 * @param string            $reason The reason.
	 *
	 * @return array{accepted: int, refused: array<string, int>} The outcome.
	 */
	private function refuseAll(array $events, string $reason): array {
		$refused = [$reason => max(1, count($events))];
		$this->metrics->refused(reasons: $refused);

		return ['accepted' => 0, 'refused' => $refused];
	}

	/**
	 * The clock, in UTC.
	 *
	 * @return DateTimeImmutable Now.
	 */
	private function now(): DateTimeImmutable {
		return DateTimeImmutable::createFromMutable($this->time->getDateTime())->setTimezone(new DateTimeZone('UTC'));
	}
}
