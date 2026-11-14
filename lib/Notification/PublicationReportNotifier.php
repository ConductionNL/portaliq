<?php

/**
 * Portaliq Publication Report Notifier
 *
 * Renders the notification the owner of error reports receives for a new
 * report on a publication, linking to the report in the portal admin
 * (publication-error-reports-and-withheld-notices REQ-PER-002).
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
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */

declare(strict_types=1);

namespace OCA\Portaliq\Notification;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Service\Publication\PublicationReportService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders a new error report's notification.
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */
class PublicationReportNotifier implements INotifier {
	/**
	 * Constructor.
	 *
	 * @param IFactory      $l10n The language factory.
	 * @param IURLGenerator $urls Builds the link and the icon.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IFactory $l10n,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The notifier's id, distinct from the app's traffic notifier.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	public function getID(): string {
		return AppIdentity::APP_ID . '-publication-reports';
	}//end getID()

	/**
	 * The notifier's name, for the notification settings.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	public function getName(): string {
		return $this->l10n->get(AppIdentity::APP_ID)->t('Error reports on publications');
	}//end getName()

	/**
	 * Render a new report's notification: which publication, on which
	 * portal, and a link to the report.
	 *
	 * @param INotification $notification The stored notification.
	 * @param string        $languageCode The user's language.
	 *
	 * @return INotification
	 *
	 * @throws UnknownNotificationException When it is not a report notification.
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== AppIdentity::APP_ID || $notification->getSubject() !== PublicationReportService::SUBJECT) {
			throw new UnknownNotificationException('Not a publication error report');
		}

		$l          = $this->l10n->get(AppIdentity::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();
		$title      = (string)($parameters['title'] ?? '');
		$portal     = (string)($parameters['portal'] ?? '');
		$id         = (string)($parameters['id'] ?? $notification->getObjectId());

		if ($title !== '') {
			$notification->setParsedSubject($l->t('Error report on "%1$s" (%2$s)', [$title, $portal]));
		} else {
			$notification->setParsedSubject($l->t('Error report on a publication (%s)', [$portal]));
		}

		$notification->setParsedMessage($l->t('A visitor reported an error. Open the report to handle it.'));
		$notification->setLink(
			$this->urls->getAbsoluteURL(
				$this->urls->linkToRoute('portaliq.dashboard.catchAll', ['path' => 'publication-reports/' . rawurlencode($id)])
			)
		);
		$notification->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(AppIdentity::APP_ID, 'app-dark.svg')));

		return $notification;
	}//end prepare()
}//end class
