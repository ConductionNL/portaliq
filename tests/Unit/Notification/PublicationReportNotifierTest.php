<?php

/**
 * Unit tests for the PublicationReportNotifier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Notification;

use OCA\Portaliq\Notification\PublicationReportNotifier;
use OCA\Portaliq\Service\Publication\PublicationReportService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * A new report's notification names the publication and links to the report.
 *
 * @covers \OCA\Portaliq\Notification\PublicationReportNotifier
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
 */
class PublicationReportNotifierTest extends TestCase {
	/**
	 * What the notification double was given.
	 *
	 * @var array<string, string>
	 */
	private array $parsed = [];

	/**
	 * The notifier over a pass-through translator.
	 *
	 * @return PublicationReportNotifier
	 */
	private function notifier(): PublicationReportNotifier {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img/app-dark.svg');
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $parameters = []): string => '/apps/portaliq/' . ($route === 'portaliq.dashboard.catchAll' ? $parameters['path'] : 'wrong')
		);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		return new PublicationReportNotifier($factory, $urls);
	}//end notifier()

	/**
	 * A notification double that records what was parsed and linked.
	 *
	 * @param string               $app        The app id.
	 * @param string               $subject    The subject key.
	 * @param array<string, mixed> $parameters The parameters.
	 *
	 * @return INotification
	 */
	private function notification(string $app, string $subject, array $parameters): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('getObjectId')->willReturn('fallback-id');
		foreach (['setParsedSubject' => 'subject', 'setParsedMessage' => 'message', 'setLink' => 'link', 'setIcon' => 'icon'] as $setter => $key) {
			$notification->method($setter)->willReturnCallback(
				function (string $value) use ($key, $notification): INotification {
					$this->parsed[$key] = $value;
					return $notification;
				}
			);
		}

		return $notification;
	}//end notification()

	/**
	 * The notification names the publication and its portal and links to the
	 * report's page in the portal admin.
	 *
	 * @return void
	 */
	public function testTheNotificationLinksToTheReport(): void {
		$this->notifier()->prepare(
			$this->notification(app: 'portaliq', subject: PublicationReportService::SUBJECT, parameters: ['id' => 'report-1', 'portal' => 'Zuiddrecht', 'title' => 'Besluit parkeerbeleid']),
			'nl'
		);

		$this->assertSame(expected: 'Error report on "Besluit parkeerbeleid" (Zuiddrecht)', actual: $this->parsed['subject']);
		$this->assertSame(expected: 'A visitor reported an error. Open the report to handle it.', actual: $this->parsed['message']);
		$this->assertSame(expected: 'https://cloud.example/apps/portaliq/publication-reports/report-1', actual: $this->parsed['link']);
	}//end testTheNotificationLinksToTheReport()

	/**
	 * Without a title the subject names the portal only.
	 *
	 * @return void
	 */
	public function testAReportWithoutATitleNamesThePortal(): void {
		$this->notifier()->prepare($this->notification(app: 'portaliq', subject: PublicationReportService::SUBJECT, parameters: ['portal' => 'Zuiddrecht']), 'en');

		$this->assertSame(expected: 'Error report on a publication (Zuiddrecht)', actual: $this->parsed['subject']);
		$this->assertSame(expected: 'https://cloud.example/apps/portaliq/publication-reports/fallback-id', actual: $this->parsed['link']);
	}//end testAReportWithoutATitleNamesThePortal()

	/**
	 * The traffic notifications and other apps' are not this notifier's.
	 *
	 * @return void
	 */
	public function testAnotherSubjectIsNotOurs(): void {
		$this->expectException(UnknownNotificationException::class);

		$this->notifier()->prepare($this->notification(app: 'portaliq', subject: 'traffic_report', parameters: []), 'en');
	}//end testAnotherSubjectIsNotOurs()

	/**
	 * The id is distinct from the traffic notifier's, which is the app id.
	 *
	 * @return void
	 */
	public function testItsIdIsItsOwn(): void {
		$this->assertSame(expected: 'portaliq-publication-reports', actual: $this->notifier()->getID());
	}//end testItsIdIsItsOwn()
}//end class
