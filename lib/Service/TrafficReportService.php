<?php

/**
 * Portaliq Traffic Report Service.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficReportDelivery;
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use OCA\Portaliq\Service\Traffic\TrafficReportPeriods;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Decides which reports are due and which alerts fire, and records what
 * it did so neither happens twice.
 *
 * ONCE PER PERIOD, BY KEY. A report's period has a key (a date, an ISO
 * week, a month) and the app config remembers the last key sent per
 * portal and report. The hourly job asks "is the key for the period that
 * just ended the one I already sent?", and sends when it is not. The
 * same for an alert: it fires the first time its condition holds in a
 * period and stays quiet for the rest of it, whatever the job's next
 * runs see. A new definition therefore sends on the job's next run, with
 * the last complete period's figures, which is also how an operator
 * checks that the recipients are right.
 *
 * The key is recorded BEFORE delivery. A mailer that throws after the
 * key is written costs one period's mail; a key written after a mailer
 * that hangs costs a mail per hour until someone notices.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */
class TrafficReportService {

	/**
	 * The app config key prefix of a sent report: `traffic_report_<portal>_<id>`.
	 */
	public const REPORT_PREFIX = 'traffic_report_';

	/**
	 * The app config key prefix of a fired alert: `traffic_alert_<portal>_<id>`.
	 */
	public const ALERT_PREFIX = 'traffic_alert_';

	/**
	 * Constructor.
	 *
	 * @param PortalResolver        $portals   Lists the published portals.
	 * @param TrafficConfigResolver $config    Resolves a portal's reports and alerts.
	 * @param TrafficEventStore     $store     Reads the daily records.
	 * @param TrafficReportPeriods  $periods   The calendar arithmetic.
	 * @param TrafficReportNumbers  $numbers   Folds records into figures.
	 * @param TrafficReportDelivery $delivery  Mails and notifies.
	 * @param IAppConfig            $appConfig Remembers what was sent.
	 * @param ITimeFactory          $time      The clock.
	 * @param LoggerInterface       $logger    The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly TrafficConfigResolver $config,
		private readonly TrafficEventStore $store,
		private readonly TrafficReportPeriods $periods,
		private readonly TrafficReportNumbers $numbers,
		private readonly TrafficReportDelivery $delivery,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Send every due report and fire every alert whose condition holds.
	 *
	 * @return array{reports: int, alerts: int} How many were sent and fired.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
	 */
	public function run(): array {
		$now = $this->now();
		$reports = 0;
		$alerts = 0;
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = trim((string)($portal['slug'] ?? ''));
			if ($slug === '') {
				continue;
			}

			$config = $this->config->resolve(portal: $portal);
			foreach ($config['reports'] as $report) {
				$reports += (int)$this->report(portal: $portal, slug: $slug, report: $report, now: $now);
			}

			foreach ($config['alerts'] as $alert) {
				$alerts += (int)$this->alert(portal: $portal, slug: $slug, alert: $alert, now: $now);
			}
		}

		$this->logger->info('Portaliq: traffic reports ran', ['reports' => $reports, 'alerts' => $alerts]);

		return ['reports' => $reports, 'alerts' => $alerts];
	}

	/**
	 * Send one report when its period is new.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 * @param string               $slug   Its slug.
	 * @param array<string, mixed> $report The resolved report.
	 * @param DateTimeImmutable    $now    The clock.
	 *
	 * @return bool True when sent.
	 */
	private function report(array $portal, string $slug, array $report, DateTimeImmutable $now): bool {
		$period = $this->periods->reportPeriod(cadence: (string)$report['cadence'], now: $now);
		$key = self::REPORT_PREFIX . $slug . '_' . (string)$report['id'];
		if ($this->appConfig->getValueString(Application::APP_ID, $key, '') === $period['key']) {
			return false;
		}

		$this->appConfig->setValueString(Application::APP_ID, $key, $period['key']);
		$current = $this->numbers->fold(records: $this->records(slug: $slug, from: $period['from'], to: $period['to']));
		$previous = $this->numbers->fold(records: $this->records(slug: $slug, from: $period['previousFrom'], to: $period['previousTo']));
		$delivered = $this->delivery->sendReport(portal: $portal, report: $report, period: $period, current: $current, previous: $previous);
		$this->logger->info('Portaliq: traffic report sent', ['portal' => $slug, 'report' => $report['id'], 'period' => $period['key'], 'deliveries' => $delivered]);

		return true;
	}

	/**
	 * Fire one alert when its condition holds for a period it has not
	 * fired in.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 * @param string               $slug   Its slug.
	 * @param array<string, mixed> $alert  The resolved alert.
	 * @param DateTimeImmutable    $now    The clock.
	 *
	 * @return bool True when fired.
	 */
	private function alert(array $portal, string $slug, array $alert, DateTimeImmutable $now): bool {
		$period = $this->periods->alertPeriod(period: (string)$alert['period'], comparison: (string)$alert['comparison'], now: $now);
		$key = self::ALERT_PREFIX . $slug . '_' . (string)$alert['id'];
		if ($this->appConfig->getValueString(Application::APP_ID, $key, '') === $period['key']) {
			return false;
		}

		$value = $this->numbers->metric(metric: (string)$alert['metric'], records: $this->records(slug: $slug, from: $period['from'], to: $period['to']));
		$change = null;
		$threshold = (float)$alert['threshold'];
		$fires = match ((string)$alert['comparison']) {
			'above' => $value > $threshold,
			'below' => $value < $threshold,
			default => false,
		};
		if ((string)$alert['comparison'] === 'changeAbove') {
			$before = $this->numbers->metric(metric: (string)$alert['metric'], records: $this->records(slug: $slug, from: $period['previousFrom'], to: $period['previousTo']));
			$change = $this->numbers->change(current: $value, previous: $before);
			$fires = ($change !== null && $change > $threshold);
		}

		if ($fires === false) {
			return false;
		}

		$this->appConfig->setValueString(Application::APP_ID, $key, $period['key']);
		$delivered = $this->delivery->sendAlert(portal: $portal, alert: $alert, period: $period, value: $value, change: $change);
		$this->logger->info('Portaliq: traffic alert fired', ['portal' => $slug, 'alert' => $alert['id'], 'period' => $period['key'], 'deliveries' => $delivered]);

		return true;
	}

	/**
	 * A portal's "all sessions" records in a span of days.
	 *
	 * @param string $slug The portal slug.
	 * @param string $from The first day.
	 * @param string $to   The last day.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 */
	private function records(string $slug, string $from, string $to): array {
		return $this->store->dailyBetween(portal: $slug, from: $from, to: $to, segment: '');
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
