<?php

/**
 * Portaliq Notifier
 *
 * Renders this app's in-app notifications: the traffic reports and alerts
 * of portal-traffic-reporting. The declarative
 * `x-openregister-notifications` dialect (ADR-031) is for notifications
 * that follow an OBJECT event; a report is sent on a calendar and an
 * alert on a threshold the aggregation crossed, neither of which is an
 * object being written, so they are dispatched by the report job and
 * rendered here.
 *
 * @category Notification
 * @package  OCA\Portaliq\Notification
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Notification;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Traffic\TrafficReportDelivery;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Turns a stored notification's subject key and parameters into the
 * words a user sees, in their language.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
 */
class Notifier implements INotifier {

	/**
	 * Constructor.
	 *
	 * @param IFactory      $l10n The language factory.
	 * @param IURLGenerator $urls For the icon.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IFactory $l10n,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()


	/**
	 * The notifier's id: the app id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
	 */
	public function getID(): string {
		return Application::APP_ID;
	}//end getID()


	/**
	 * The notifier's name, for the notification settings.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
	 */
	public function getName(): string {
		return $this->l10n->get(Application::APP_ID)->t('Portaliq');
	}//end getName()


	/**
	 * Render one notification.
	 *
	 * @param INotification $notification The stored notification.
	 * @param string        $languageCode The user's language.
	 *
	 * @return INotification The rendered notification.
	 *
	 * @throws UnknownNotificationException When it is not one of ours.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException('Not a Portaliq notification');
		}

		$l = $this->l10n->get(Application::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();
		$name = (string)($parameters['name'] ?? '');
		$portal = (string)($parameters['portal'] ?? '');
		switch ($notification->getSubject()) {
			case TrafficReportDelivery::SUBJECT_REPORT:
				$notification->setParsedSubject($l->t('Traffic report: %1$s (%2$s)', [$name, $portal]));
				$notification->setParsedMessage($l->t('The report for %s was sent. Open the Traffic page for the figures.', [(string)($parameters['span'] ?? '')]));
				break;
			case TrafficReportDelivery::SUBJECT_ALERT:
				$notification->setParsedSubject($l->t('Traffic alert: %1$s (%2$s)', [$name, $portal]));
				$notification->setParsedMessage((string)($parameters['line'] ?? ''));
				break;
			default:
				throw new UnknownNotificationException('Unknown Portaliq notification subject');
		}

		$notification->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')));

		return $notification;
	}//end prepare()
}//end class
