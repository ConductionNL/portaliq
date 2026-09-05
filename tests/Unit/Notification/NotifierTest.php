<?php

/**
 * Unit tests for the Notifier.
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Notification;

use OCA\Portaliq\Notification\Notifier;
use OCA\Portaliq\Service\Traffic\TrafficReportDelivery;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * A report and an alert render their words; anything else is not ours.
 */
class NotifierTest extends TestCase {

	/**
	 * The notifier over a pass-through translator.
	 *
	 * @return Notifier The notifier.
	 */
	private function notifier(): Notifier {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://x' . $path);

		return new Notifier($factory, $urls);
	}//end notifier()


	/**
	 * A notification double that records what was parsed.
	 *
	 * @param string                $subject    The subject key.
	 * @param array<string, string> $parameters The parameters.
	 * @param array<string, string> $parsed     Filled with subject, message and icon.
	 * @param string                $app        The app id.
	 *
	 * @return INotification The double.
	 */
	private function notification(string $subject, array $parameters, array &$parsed, string $app = 'portaliq'): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		foreach (['setParsedSubject' => 'subject', 'setParsedMessage' => 'message', 'setIcon' => 'icon'] as $method => $key) {
			$notification->method($method)->willReturnCallback(
				function (string $value) use (&$parsed, $key, $notification): INotification {
					$parsed[$key] = $value;

					return $notification;
				}
			);
		}

		return $notification;
	}//end notification()


	/**
	 * @return void
	 */
	public function testAReportAndAnAlertRender(): void {
		$parsed = [];
		$this->notifier()->prepare(
			$this->notification(TrafficReportDelivery::SUBJECT_REPORT, ['name' => 'Weekly', 'portal' => 'Open Tilburg', 'span' => '2026-08-31 / 2026-09-06'], $parsed),
			'en'
		);
		$this->assertSame('Traffic report: Weekly (Open Tilburg)', $parsed['subject']);
		$this->assertStringContainsString('2026-08-31 / 2026-09-06', $parsed['message']);
		$this->assertSame('https://x/img/app-dark.svg', $parsed['icon']);

		$parsed = [];
		$this->notifier()->prepare(
			$this->notification(TrafficReportDelivery::SUBJECT_ALERT, ['name' => 'Busy', 'portal' => 'Open Tilburg', 'line' => 'pageViews reached 60, above the threshold of 50.'], $parsed),
			'en'
		);
		$this->assertSame('Traffic alert: Busy (Open Tilburg)', $parsed['subject']);
		$this->assertSame('pageViews reached 60, above the threshold of 50.', $parsed['message']);
		$this->assertSame('portaliq', $this->notifier()->getID());
	}//end testAReportAndAnAlertRender()


	/**
	 * @return void
	 */
	public function testAnotherAppsOrAnUnknownSubjectIsNotHandled(): void {
		$parsed = [];
		$this->expectException(UnknownNotificationException::class);
		$this->notifier()->prepare($this->notification('traffic_report', [], $parsed, 'files'), 'en');
	}//end testAnotherAppsOrAnUnknownSubjectIsNotHandled()


	/**
	 * @return void
	 */
	public function testAnUnknownSubjectIsNotHandled(): void {
		$parsed = [];
		$this->expectException(UnknownNotificationException::class);
		$this->notifier()->prepare($this->notification('something_else', [], $parsed), 'en');
	}//end testAnUnknownSubjectIsNotHandled()
}//end class
