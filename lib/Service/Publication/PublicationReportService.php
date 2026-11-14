<?php

/**
 * Portaliq Publication Report Service
 *
 * A visitor's report of an error in a publication, from receipt to close:
 * the portal's switch and owner are checked, the publication is read the way
 * an anonymous visitor reads it, the report is stored without an address or
 * a user agent, and every owner gets a Nextcloud notification. The owner then
 * closes it, and a closed report's contact address is erased after 30 days
 * (publication-error-reports-and-withheld-notices REQ-PER-002).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Publication
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

namespace OCA\Portaliq\Service\Publication;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalObjectWriterInterface;
use OCA\Portaliq\Service\PortalReportOwnerCheck;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Receives, lists and closes the error reports on a portal's publications.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- one flow over storage, the public read, users, groups, notifications and a cache.
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */
class PublicationReportService {
	/**
	 * The register and schema a report lives in.
	 */
	public const REGISTER = 'portaliq';
	public const SCHEMA   = 'publicationErrorReport';

	/**
	 * The notification subject an owner receives.
	 */
	public const SUBJECT = 'publication_error_report';

	/**
	 * The longest description accepted.
	 */
	public const MAX_DESCRIPTION = 2000;

	/**
	 * Reports one publication takes per hour, from all addresses together.
	 */
	public const PER_PUBLICATION_PER_HOUR = 10;

	/**
	 * Days a closed report keeps its contact address.
	 */
	public const ERASE_AFTER_DAYS = 30;

	/**
	 * The statuses an owner may close a report with.
	 */
	public const CLOSED = ['handled', 'not-applicable'];

	/**
	 * The outcomes of submit() and close().
	 */
	public const OK              = 'ok';
	public const OFF             = 'off';
	public const UNKNOWN         = 'unknown';
	public const INVALID         = 'invalid';
	public const TOO_LONG        = 'too_long';
	public const TOO_MANY        = 'too_many';
	public const NOT_OWNER       = 'not_owner';
	public const FAILED          = 'failed';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriterInterface     $writer        Stores and closes reports.
	 * @param ScopedCollectionReaderInterface $reader        Lists a portal's reports.
	 * @param PublicPublicationRead           $publications  Reads a publication anonymously.
	 * @param IUserManager                    $users         Whether the owner is a user.
	 * @param IGroupManager                   $groups        The owner group's members.
	 * @param INotificationManager            $notifications Tells the owners.
	 * @param ICacheFactory                   $caches        Counts the reports per publication.
	 * @param ITimeFactory                    $time          The clock.
	 * @param LoggerInterface                 $logger        Notes a failed notification.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalObjectWriterInterface $writer,
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly PublicPublicationRead $publications,
		private readonly IUserManager $users,
		private readonly IGroupManager $groups,
		private readonly INotificationManager $notifications,
		private readonly ICacheFactory $caches,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a portal takes error reports: switched on with a literal true
	 * and an owner named. The owner's existence is checked when the portal is
	 * saved (PortalReportOwnerCheck).
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
	 */
	public function isOn(array $portal): bool {
		return self::switchedOn(portal: $portal);
	}//end isOn()

	/**
	 * The same answer without an instance, for the public site record.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
	 */
	public static function switchedOn(array $portal): bool {
		$owner = $portal[PortalReportOwnerCheck::OWNER] ?? '';

		return ($portal[PortalReportOwnerCheck::SWITCH] ?? false) === true && is_string($owner) === true && trim($owner) !== '';
	}//end switchedOn()

	/**
	 * Receive one report.
	 *
	 * Only the declared fields are stored, with `receivedAt` and status `new`:
	 * no address, no user agent. The publication must be one the anonymous
	 * public read returns, so a draft or an unknown id is refused like an
	 * unknown one, and nothing is stored.
	 *
	 * @param array<string, mixed> $portal The portal the report came from.
	 * @param array<string, mixed> $input  `publication`, `document`, `description`, `contactEmail`.
	 *
	 * @return array{outcome: string, report?: array<string, mixed>}
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	public function submit(array $portal, array $input): array {
		if ($this->isOn(portal: $portal) === false) {
			return ['outcome' => self::OFF];
		}

		$description = trim(self::text(value: ($input['description'] ?? '')));
		$email       = trim(self::text(value: ($input['contactEmail'] ?? '')));
		if ($description === '' || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
			return ['outcome' => self::INVALID];
		}

		if (mb_strlen($description) > self::MAX_DESCRIPTION) {
			return ['outcome' => self::TOO_LONG];
		}

		$publicationId = trim(self::text(value: ($input['publication'] ?? '')));
		$read          = $this->publications->read(id: $publicationId);
		if ($read['status'] !== PublicPublicationRead::FOUND) {
			return ['outcome' => self::UNKNOWN];
		}

		$slug = self::text(value: ($portal['slug'] ?? ''));
		if ($this->countAgainst(portal: $slug, publication: $publicationId) === false) {
			return ['outcome' => self::TOO_MANY];
		}

		$report = $this->writer->createAnonymousObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			data: [
				'portal'           => $slug,
				'publication'      => $publicationId,
				'publicationTitle' => mb_substr(self::text(value: ($read['body']['title'] ?? '')), 0, 255),
				'document'         => mb_substr(trim(self::text(value: ($input['document'] ?? ''))), 0, 255),
				'description'      => $description,
				'contactEmail'     => $email,
				'status'           => 'new',
				'receivedAt'       => $this->now()->format(DateTimeInterface::ATOM),
			]
		);
		if ($report === null) {
			return ['outcome' => self::FAILED];
		}

		$this->notifyOwners(portal: $portal, report: $report);

		return ['outcome' => self::OK, 'report' => $report];
	}//end submit()

	/**
	 * The user ids a report reaches: the owner when it is a user, else every
	 * member of the owner group. Checked in the order PortalReportOwnerCheck
	 * checks, so a name that is both is the user.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	public function recipients(array $portal): array {
		$owner = $this->ownerOf(portal: $portal);
		if ($owner === '') {
			return [];
		}

		if ($this->users->userExists($owner) === true) {
			return [$owner];
		}

		$group = $this->groups->get($owner);
		if ($group === null) {
			return [];
		}

		$ids = [];
		foreach ($group->getUsers() as $user) {
			$ids[] = $user->getUID();
		}

		return $ids;
	}//end recipients()

	/**
	 * Whether a user owns a portal's reports: the owner itself, or a member
	 * of the owner group.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 * @param string               $userId The user.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	public function isOwner(array $portal, string $userId): bool {
		$owner = $this->ownerOf(portal: $portal);
		if ($owner === '' || $userId === '') {
			return false;
		}

		return $owner === $userId || $this->groups->isInGroup($userId, $owner) === true;
	}//end isOwner()

	/**
	 * A portal's reports, newest first, for its owner.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	public function reportsOf(array $portal): array {
		$slug = self::text(value: ($portal['slug'] ?? ''));
		if ($slug === '') {
			return [];
		}

		$rows = array_values(
			array_filter(
				$this->reader->readCollection(register: self::REGISTER, schema: self::SCHEMA, scopeField: 'portal', subjectRef: $slug),
				static fn ($row): bool => is_array($row) === true && ($row['portal'] ?? '') === $slug
			)
		);
		usort($rows, static fn (array $one, array $two): int => strcmp((string)($two['receivedAt'] ?? ''), (string)($one['receivedAt'] ?? '')));

		return $rows;
	}//end reportsOf()

	/**
	 * Close one report as handled or not applicable, for its owner only.
	 *
	 * @param array<string, mixed> $portal The portal the report belongs to.
	 * @param string               $id     The report's id.
	 * @param string               $status `handled` or `not-applicable`.
	 * @param string               $userId Who closes it.
	 *
	 * @return array{outcome: string, report?: array<string, mixed>}
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	public function close(array $portal, string $id, string $status, string $userId): array {
		if ($this->isOwner(portal: $portal, userId: $userId) === false) {
			return ['outcome' => self::NOT_OWNER];
		}

		if (in_array($status, self::CLOSED, true) === false || $id === '') {
			return ['outcome' => self::INVALID];
		}

		$report = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			// The portal is the ownership boundary: the writer re-reads the
			// row and refuses one that belongs to another portal.
			scopeField: 'portal',
			subjectRef: self::text(value: ($portal['slug'] ?? '')),
			organisation: '',
			id: $id,
			data: [
				'status'   => $status,
				'closedAt' => $this->now()->format(DateTimeInterface::ATOM),
				'closedBy' => $userId,
			]
		);
		if ($report === null) {
			return ['outcome' => self::UNKNOWN];
		}

		return ['outcome' => self::OK, 'report' => $report];
	}//end close()

	/**
	 * Erase the contact address of each report of a portal closed more than
	 * 30 days ago. An open report keeps its address.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return int How many addresses were erased.
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 */
	public function eraseExpiredContacts(array $portal): int {
		$slug   = self::text(value: ($portal['slug'] ?? ''));
		$cutoff = $this->now()->modify('-' . self::ERASE_AFTER_DAYS . ' days');
		$erased = 0;
		foreach ($this->reportsOf(portal: $portal) as $report) {
			if ($this->contactExpired(report: $report, cutoff: $cutoff) === false) {
				continue;
			}

			$id      = (string)($report['id'] ?? $report['uuid'] ?? (($report['@self'] ?? [])['id'] ?? ''));
			$written = $this->writer->updateObject(
				register: self::REGISTER,
				schema: self::SCHEMA,
				scopeField: 'portal',
				subjectRef: $slug,
				organisation: '',
				id: $id,
				data: ['contactEmail' => '']
			);
			if ($written !== null) {
				$erased++;
			}
		}

		return $erased;
	}//end eraseExpiredContacts()

	/**
	 * Whether a report's contact address is due for erasure: it has one, it
	 * is closed, and it was closed before the cutoff.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param DateTimeImmutable    $cutoff Thirty days ago.
	 *
	 * @return bool
	 */
	private function contactExpired(array $report, DateTimeImmutable $cutoff): bool {
		if (self::text(value: ($report['contactEmail'] ?? '')) === ''
			|| in_array(($report['status'] ?? ''), self::CLOSED, true) === false
		) {
			return false;
		}

		try {
			$closedAt = new DateTimeImmutable(self::text(value: ($report['closedAt'] ?? '')));
		} catch (Throwable) {
			return false;
		}

		return self::text(value: ($report['closedAt'] ?? '')) !== '' && $closedAt < $cutoff;
	}//end contactExpired()

	/**
	 * Notify every recipient of a new report. A failed notification never
	 * fails the report: it is stored and sits in the queue.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 * @param array<string, mixed> $report The stored report.
	 *
	 * @return void
	 */
	private function notifyOwners(array $portal, array $report): void {
		$id = (string)($report['id'] ?? $report['uuid'] ?? (($report['@self'] ?? [])['id'] ?? ''));
		foreach ($this->recipients(portal: $portal) as $userId) {
			try {
				$notification = $this->notifications->createNotification();
				$notification->setApp(AppIdentity::APP_ID)
					->setUser($userId)
					->setDateTime($this->time->getDateTime())
					->setObject(self::SCHEMA, mb_substr($id, 0, 64))
					->setSubject(
						self::SUBJECT,
						[
							'id'     => $id,
							'portal' => self::text(value: ($portal['title'] ?? $portal['slug'] ?? '')),
							'title'  => self::text(value: ($report['publicationTitle'] ?? '')),
						]
					);
				$this->notifications->notify($notification);
			} catch (Throwable $failure) {
				$this->logger->warning('Portaliq: an error report notification failed', ['exception' => $failure->getMessage()]);
			}
		}
	}//end notifyOwners()

	/**
	 * Count one report against its publication's hourly limit.
	 *
	 * @param string $portal      The portal slug.
	 * @param string $publication The publication id.
	 *
	 * @return bool False once the publication has taken its reports this hour.
	 */
	private function countAgainst(string $portal, string $publication): bool {
		$cache = $this->caches->createDistributed(AppIdentity::APP_ID . '-publication-reports');
		$key   = hash('sha256', $portal . '|' . $publication . '|' . (int)floor($this->time->getTime() / 3600));
		$count = (int)($cache->get($key) ?? 0);
		if ($count >= self::PER_PUBLICATION_PER_HOUR) {
			return false;
		}

		$cache->set($key, $count + 1, 3600);

		return true;
	}//end countAgainst()

	/**
	 * The portal's owner of error reports, trimmed, or ''.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return string
	 */
	private function ownerOf(array $portal): string {
		return trim(self::text(value: ($portal[PortalReportOwnerCheck::OWNER] ?? '')));
	}//end ownerOf()

	/**
	 * The clock's now.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface($this->time->getDateTime());
	}//end now()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		return '';
	}//end text()
}//end class
