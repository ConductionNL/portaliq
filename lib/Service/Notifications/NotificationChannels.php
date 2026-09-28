<?php

/**
 * Which channels a notice goes out on, and the push itself
 * (inbox-notifications-and-preferences, REQ-NAP-007).
 *
 * A resident chooses per kind of notice and per channel. Two kinds:
 * `case.updated` for every change rule, `message.created` for a new message.
 * A missing choice means on. The inbox message itself is not a choice; the
 * account-wide e-mail opt-out is checked by the dispatch job, before this.
 *
 * A push goes out only when the resident registered a device, through
 * PushDeliveryService, which honours their quiet hours.
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
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use OCA\Portaliq\Service\PortalObjectReader;
use Throwable;

/**
 * Reads the resident's choices and sends the push.
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
 */
class NotificationChannels {

	/**
	 * The kind every change rule counts as.
	 *
	 * @var string
	 */
	public const KIND_CASE_UPDATED = 'case.updated';

	/**
	 * The kind a new message counts as.
	 *
	 * @var string
	 */
	public const KIND_MESSAGE_CREATED = 'message.created';

	/**
	 * The e-mail channel.
	 *
	 * @var string
	 */
	public const CHANNEL_EMAIL = 'email';

	/**
	 * The push channel.
	 *
	 * @var string
	 */
	public const CHANNEL_PUSH = 'push';

	/**
	 * Wire the channels. Without a push service or a reader, no push is sent.
	 *
	 * @param PushDeliveryService|null $push   Sends the web push.
	 * @param PortalObjectReader|null  $reader Reads the resident's devices.
	 */
	public function __construct(
		private readonly ?PushDeliveryService $push = null,
		private readonly ?PortalObjectReader $reader = null,
	) {
	}//end __construct()

	/**
	 * The record a change rule is about, from the job argument, or [].
	 *
	 * @param array<string, mixed> $argument The job argument.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-notification-leads-to-the-record-req-nap-005
	 */
	public function record(array $argument): array {
		$record = ($argument['record'] ?? null);
		if (is_array($record) === false || (string)($record['id'] ?? '') === '') {
			return [];
		}

		return [
			'app' => (string)($record['app'] ?? ''),
			'collection' => (string)($record['collection'] ?? ''),
			'id' => (string)$record['id'],
			'label' => (string)($record['label'] ?? ''),
		];
	}//end record()

	/**
	 * The kind of a notice: a notice about a record is a case change.
	 *
	 * @param array<string, string> $record The record, or [].
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function kindOf(array $record): string {
		if ($record !== []) {
			return self::KIND_CASE_UPDATED;
		}

		return self::KIND_MESSAGE_CREATED;
	}//end kindOf()

	/**
	 * Whether the account wants this kind on this channel. A missing choice
	 * means on.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param string               $kind    The kind.
	 * @param string               $channel The channel.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function wants(array $account, string $kind, string $channel): bool {
		if ($channel === self::CHANNEL_PUSH && ($this->push === null || $this->reader === null)) {
			return false;
		}

		// The account-wide e-mail opt-out (notification-preferences-per-role)
		// switches e-mail off for every kind; a kind's choice can only switch
		// it off further.
		$accountWide = ($account['notificationChannels'] ?? null);
		if ($channel === self::CHANNEL_EMAIL && is_array($accountWide) === true && ($accountWide[self::CHANNEL_EMAIL] ?? true) === false) {
			return false;
		}

		$preferences = ($account['notificationPreferences'] ?? null);
		if (is_array($preferences) === false || is_array(($preferences[$kind] ?? null)) === false) {
			return true;
		}

		return ($preferences[$kind][$channel] ?? true) !== false;
	}//end wants()

	/**
	 * Send a push to the resident's devices.
	 *
	 * @param string $subjectRef The resident.
	 * @param string $title      The title.
	 * @param string $body       The body.
	 *
	 * @return string|null Null when no device is registered (nothing to log),
	 *                     else `sent` (delivered, or queued for after quiet
	 *                     hours) or `failed`.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function push(string $subjectRef, string $title, string $body): ?string {
		if ($this->push === null || $this->reader === null) {
			return null;
		}

		if ($this->hasDevice(subjectRef: $subjectRef) === false) {
			return null;
		}

		try {
			$delivered = $this->push->deliver(subjectRef: $subjectRef, title: $title, body: $body);
		} catch (Throwable $e) {
			$delivered = false;
		}

		if ($delivered === true) {
			return 'sent';
		}

		return 'failed';
	}//end push()

	/**
	 * Every kind and channel the resident chooses, with a stored `false` kept
	 * and anything else on.
	 *
	 * @param mixed $stored The stored `notificationPreferences`.
	 *
	 * @return array<string, array<string, bool>>
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function preferences(mixed $stored): array {
		if (is_array($stored) === false) {
			$stored = [];
		}

		$complete = [];
		foreach ([self::KIND_CASE_UPDATED, self::KIND_MESSAGE_CREATED] as $kind) {
			foreach ([self::CHANNEL_EMAIL, self::CHANNEL_PUSH] as $channel) {
				$choices = ($stored[$kind] ?? null);
				$complete[$kind][$channel] = (is_array($choices) === false || ($choices[$channel] ?? true) !== false);
			}
		}

		return $complete;
	}//end preferences()

	/**
	 * The stored choices with the asked ones applied: only the known kinds and
	 * channels, and only boolean values.
	 *
	 * @param mixed                $stored The stored `notificationPreferences`.
	 * @param array<string, mixed> $asked  The choices sent.
	 *
	 * @return array<string, array<string, bool>>
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function merged(mixed $stored, array $asked): array {
		$preferences = $this->preferences(stored: $stored);
		foreach ($preferences as $kind => $choices) {
			foreach (array_keys($choices) as $channel) {
				$value = ($asked[$kind][$channel] ?? null);
				if (is_bool($value) === true) {
					$preferences[$kind][$channel] = $value;
				}
			}
		}

		return $preferences;
	}//end merged()

	/**
	 * Whether the resident registered a device for push.
	 *
	 * @param string $subjectRef The resident.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-choices-live-on-the-inbox-page-req-nap-008
	 */
	public function hasDevice(string $subjectRef): bool {
		if ($this->reader === null) {
			return false;
		}

		return $this->reader->readCollection(
			register: 'portaliq',
			schema: 'pushSubscription',
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: '',
			limit: 1
		) !== [];
	}//end hasDevice()
}//end class
