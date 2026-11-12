<?php

/**
 * Asks the case app for the recipient of one inbox message that goes to the
 * resident's government message box.
 *
 * The recipient is handed back to the sender only: it is written to no
 * object, no log line and no response (inbox-berichtenbox-channel,
 * REQ-MBC-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-case-app-names-the-recipient-and-portaliq-does-not-keep-it-req-mbc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\TimelineProviderMethod;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The recipient the case app names for one message. Built by MessageBoxSender.
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-case-app-names-the-recipient-and-portaliq-does-not-keep-it-req-mbc-002
 */
final class MessageBoxRecipient {
	/**
	 * Constructor.
	 *
	 * @param PortalProviderLocator $locator Finds the case app's provider.
	 * @param LoggerInterface       $logger  The logger; never given the recipient.
	 */
	public function __construct(
		private readonly PortalProviderLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The recipient of this message, trimmed, or null when the case app
	 * names none (it keeps the message in the portal).
	 *
	 * @param array<string, string> $record The message's reference.
	 * @param string                $method The declared recipient method.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-case-app-names-the-recipient-and-portaliq-does-not-keep-it-req-mbc-002
	 */
	public function forRecord(array $record, string $method): ?string {
		return $this->trimmed(value: $this->provided(record: $record, method: $method));
	}//end forRecord()

	/**
	 * What the case app's recipient method answers for this message; null
	 * when the app or the method is missing or the method fails.
	 *
	 * @param array<string, string> $record The message's reference.
	 * @param string                $method The declared recipient method.
	 *
	 * @return mixed
	 */
	private function provided(array $record, string $method): mixed {
		$provider = $this->locator->locate(appId: $record['app']);
		if ($provider === null || (new TimelineProviderMethod())->callableOn(provider: $provider, method: $method) === false) {
			return null;
		}

		try {
			return $provider->{$method}($record['id']);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Portaliq: message box recipient method failed',
				['app' => $record['app'], 'method' => $method, 'exception' => get_class($e)]
			);
			return null;
		}
	}//end provided()

	/**
	 * The answered recipient, trimmed; null when it is not non-blank text.
	 *
	 * @param mixed $value What the recipient method answered.
	 *
	 * @return string|null
	 */
	private function trimmed(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end trimmed()
}//end class
