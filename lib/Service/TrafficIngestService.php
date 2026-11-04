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
use OCA\Portaliq\Service\Traffic\TrafficIngestPipeline;
use OCA\Portaliq\Service\Traffic\TrafficMetrics;
use OCA\Portaliq\Service\Traffic\TrafficRecordDerivers;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Turns a batch of posted events into stored records, for one portal.
 *
 * THIS IS THE ONE PLACE THE ADDRESS AND THE USER AGENT EXIST. They arrive in
 * `$context`, they are read to derive a visitor hash, a device family and
 * a region, and they are not written anywhere: not to the record, not to
 * the log, not to the metrics. Every derived value is only KEPT when the
 * portal enabled its dimension. A portal session bearer, when the batch
 * carries one, becomes the account's pseudonymous reference on the same
 * terms (portal-traffic-visitors-and-geo).
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
 *
 * @psalm-type IngestBatch = array{
 *     config: array<string, mixed>,
 *     context: array<string, mixed>,
 *     serverSide: bool,
 *     now: DateTimeImmutable,
 *     slug: string,
 *     hasConsent: bool,
 *     derived?: array<string, string>
 * }
 */
class TrafficIngestService {

	/**
	 * Constructor.
	 *
	 * @param TrafficIngestPipeline $pipeline Finds the portal, resolves its configuration, validates and stores.
	 * @param TrafficRecordDerivers $derivers Derive the visitor hash and the record's families.
	 * @param TrafficMetrics        $metrics  Counts what happened.
	 * @param ITimeFactory          $time     The clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficIngestPipeline $pipeline,
		private readonly TrafficRecordDerivers $derivers,
		private readonly TrafficMetrics $metrics,
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
		foreach ($this->pipeline->portals->allPublishedPortals() as $candidate) {
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
	 * @param array<string, mixed>             $context ip, userAgent, acceptLanguage, consent, serverSide, allowOld.
	 *
	 * @return array{accepted: int, refused: array<string, int>} What happened.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-an-ip-address-must-not-be-stored
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function ingestForPortal(array $portal, array $events, array $context = []): array {
		$batch = $this->openBatch(portal: $portal, context: $context);
		if (is_string($batch) === true) {
			return $this->refuseAll(events: $events, reason: $batch);
		}

		$records = [];
		$refused = [];
		foreach ($events as $event) {
			$admitted = $this->admit(event: $event, batch: $batch);
			if (is_string($admitted) === true) {
				$refused[$admitted] = ($refused[$admitted] ?? 0) + 1;
				continue;
			}

			$records[] = $admitted;
		}

		return $this->store(records: $records, refused: $refused);
	}

	/**
	 * The batch a request opens, or the reason the whole batch is refused.
	 *
	 * A roll-up portal (portal-traffic-reporting) is the sum of others and
	 * has no visitors of its own; a batch aimed at it is refused whole,
	 * whatever it carries. A crawler is refused as a BATCH, counted under one
	 * reason, before any event is looked at. It is not a visitor and it
	 * should not inflate a page's numbers by reading every link on it.
	 *
	 * @param array<string, mixed> $portal  The portal record.
	 * @param array<string, mixed> $context The request context.
	 *
	 * @return IngestBatch|string
	 */
	private function openBatch(array $portal, array $context): array|string {
		$config = $this->pipeline->config->resolve(portal: $portal);
		if ($config['rollupOf'] !== []) {
			return 'rollup-portal';
		}

		$serverSide = ($context['serverSide'] ?? false) === true;
		$agent      = $this->derivers->agents->classify(userAgent: (string)($context['userAgent'] ?? ''));
		if ($serverSide === false && $agent['bot'] === true) {
			return 'bot';
		}

		$batch = [
			'config' => $config,
			'context' => $context,
			'serverSide' => $serverSide,
			'now' => $this->now(),
			'slug' => (string)($portal['slug'] ?? ''),
			'hasConsent' => ($context['consent'] ?? false) === true,
		];
		$batch['derived'] = $this->derivedFromRequest(agent: $agent, batch: $batch);

		return $batch;
	}

	/**
	 * The record one event becomes, or the reason it is refused.
	 *
	 * A surface the portal declared off-limits leaves no row at all
	 * (a-report-without-an-account REQ-RWA-007). The reporting page is the
	 * case this exists for: a visitor row on it records that somebody opened
	 * the whistleblowing form, which is precisely the fact that must not
	 * exist anywhere.
	 *
	 * @param mixed $event The event as posted.
	 * @param IngestBatch $batch The open batch.
	 *
	 * @return array<string, mixed>|string
	 */
	private function admit(mixed $event, array $batch): array|string {
		if (is_array($event) === false) {
			return 'malformed-event';
		}

		$result = $this->pipeline->validator->validate(
			event: $event,
			config: $batch['config'],
			hasConsent: $batch['hasConsent'],
			resolver: $this->pipeline->config,
			context: ['serverSide' => $batch['serverSide'], 'allowOld' => (($batch['context']['allowOld'] ?? false) === true)],
			now: $batch['now']
		);
		if ($result['ok'] === false) {
			return (string)($result['reason'] ?? 'refused');
		}

		if ($this->isExcluded(event: $result['event'], config: $batch['config']) === true) {
			return 'excluded-path';
		}

		return $this->record(event: $result['event'], batch: $batch);
	}

	/**
	 * Store the admitted records, count what the store did not keep, and report both to the metrics.
	 *
	 * @param array<int, array<string, mixed>> $records The admitted records.
	 * @param array<string, int>               $refused The refusals so far, by reason.
	 *
	 * @return array{accepted: int, refused: array<string, int>}
	 */
	private function store(array $records, array $refused): array {
		$accepted = $this->pipeline->store->append(records: $records);
		if ($accepted < count($records)) {
			$refused['storage-failed'] = ($refused['storage-failed'] ?? 0) + (count($records) - $accepted);
		}

		$this->metrics->accepted(count: $accepted);
		$this->metrics->refused(reasons: $refused);

		return ['accepted' => $accepted, 'refused' => $refused];
	}

	/**
	 * Whether this event happened on a surface the portal excluded.
	 *
	 * A declared path matches itself and everything under it, so declaring
	 * `/melding` covers the form, the confirmation and the thread without the
	 * operator having to enumerate them and get one wrong.
	 *
	 * @param array<string, mixed> $event  The validated event.
	 * @param array<string, mixed> $config The resolved configuration.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	private function isExcluded(array $event, array $config): bool {
		$excluded = (array)($config['excludedPaths'] ?? []);
		if ($excluded === []) {
			return false;
		}

		$path = rtrim($this->derivers->referrers->path(location: (string)($event['pageLocation'] ?? '')), '/');
		if ($path === '') {
			$path = '/';
		}

		foreach ($excluded as $candidate) {
			$prefix = (string)$candidate;
			if ($path === $prefix || str_starts_with($path, $prefix . '/') === true) {
				return true;
			}
		}

		return false;
	}//end isExcluded()

	/**
	 * One stored record from one validated event.
	 *
	 * @param array<string, mixed> $event The validated event.
	 * @param IngestBatch          $batch The open batch.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function record(array $event, array $batch): array {
		$campaign = $this->campaignOf(event: $event, batch: $batch);
		$perEvent = $this->perEventDimensions(event: $event, campaign: $campaign);
		$location = (string)$event['pageLocation'];
		$now      = $batch['now'];

		$record = $event + [
			'portal' => $batch['slug'],
			'receivedAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
			'visitorHash' => $this->visitorHash(event: $event, batch: $batch),
			'pagePath' => $this->derivers->referrers->path(location: $location),
			'consent' => $batch['hasConsent'],
			'expires' => $now->modify('+' . (int)$batch['config']['retentionDays'] . ' days')->format('Y-m-d\TH:i:s\Z'),
		] + $batch['derived'];

		foreach ($perEvent as $key => $value) {
			if ($value !== '' && in_array($key, $batch['config']['dimensions'], true) === true) {
				$record[$key] = $value;
			}
		}

		return $record + $this->linkedAccount(config: $batch['config'], context: $batch['context']);
	}

	/**
	 * The campaign attribution of an event: its URL's, with a mail event's envelope winning.
	 *
	 * A mail event carries its attribution in the envelope; the URL it points
	 * at may carry the same, and the envelope wins.
	 *
	 * @param array<string, mixed> $event The validated event.
	 * @param IngestBatch          $batch The open batch.
	 *
	 * @return array<string, string>
	 */
	private function campaignOf(array $event, array $batch): array {
		$campaign = $this->derivers->referrers->campaign(location: (string)$event['pageLocation']);
		if (($batch['context']['serverSide'] ?? false) === true) {
			foreach (['campaign', 'source', 'medium', 'content', 'term'] as $key) {
				if (($event[$key] ?? '') !== '') {
					$campaign[$key] = (string)$event[$key];
				}
			}
		}

		return $campaign;
	}

	/**
	 * The dimensions read from one event: referrer host, channel and campaign.
	 *
	 * @param array<string, mixed>  $event    The validated event.
	 * @param array<string, string> $campaign The event's campaign attribution.
	 *
	 * @return array<string, string>
	 */
	private function perEventDimensions(array $event, array $campaign): array {
		$referrerHost = $this->derivers->referrers->host(referrer: (string)($event['pageReferrer'] ?? ''));
		$pageHost     = $this->derivers->referrers->host(referrer: (string)$event['pageLocation']);

		return [
			'referrerHost' => $referrerHost,
			'channel' => $this->derivers->referrers->channel(referrerHost: $referrerHost, pageHost: $pageHost, campaign: $campaign),
		] + $campaign;
	}

	/**
	 * ACCOUNT LINKING (Ruben, decision 6). The reference is the portal
	 * account's pseudonymous subjectRef, resolved at the edge from a
	 * portal session bearer, and it is kept ONLY for a portal whose
	 * operator switched the link on. Never a BSN, a KVK number, an email
	 * address or a name: none of those are in the context.
	 *
	 * @param array<string, mixed> $config  The resolved configuration.
	 * @param array<string, mixed> $context The request context.
	 *
	 * @return array<string, string> `['userRef' => ...]`, or [].
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-account-linking-must-attach-only-a-pseudonymous-reference
	 */
	private function linkedAccount(array $config, array $context): array {
		$userRef = trim((string)($context['userRef'] ?? ''));
		if ($userRef === '' || ($config['sensitive']['accountLinking'] ?? false) !== true) {
			return [];
		}

		return ['userRef' => mb_substr($userRef, 0, 128)];
	}

	/**
	 * The dimensions derived once per request, kept only when enabled.
	 *
	 * @param array<string, mixed> $agent The classified user agent.
	 * @param IngestBatch          $batch The batch being opened.
	 *
	 * @return array<string, string> Enabled derived dimensions.
	 */
	private function derivedFromRequest(array $agent, array $batch): array {
		$config     = $batch['config'];
		$candidates = [
			'deviceType' => (string)$agent['deviceType'],
			'browser' => (string)$agent['browser'],
			'os' => (string)$agent['os'],
			'language' => $this->language(header: (string)($batch['context']['acceptLanguage'] ?? '')),
		];

		$granularity = (string)$config['regionGranularity'];
		if ($granularity !== 'none' && in_array('region', $config['dimensions'], true) === true) {
			$candidates['region'] = (string)($this->derivers->geo->resolve(address: (string)($batch['context']['ip'] ?? ''), granularity: $granularity) ?? '');
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
	 * @param array<string, mixed> $event The validated event.
	 * @param IngestBatch          $batch The open batch.
	 *
	 * @return string The hash.
	 */
	private function visitorHash(array $event, array $batch): string {
		$context = $batch['context'];
		if (($context['serverSide'] ?? false) === true) {
			return $this->derivers->hasher->hash(portal: $batch['slug'], parts: ['contact', (string)($event['contactRef'] ?? '')]);
		}

		return $this->derivers->hasher->hash(
			portal: $batch['slug'],
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
