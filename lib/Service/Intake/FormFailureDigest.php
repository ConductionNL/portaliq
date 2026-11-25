<?php

/**
 * Portaliq Form Failure Digest
 *
 * Once a day, from 07:00 server time, each portal's administrators get one
 * mail naming the forms whose submits failed in the past 24 hours, with the
 * error codes the residents saw. A quiet day sends nothing.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The daily digest of failed submits, one mail per portal administrator.
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */
class FormFailureDigest {
	/**
	 * The hour of the day, server time, from which the digest goes out.
	 */
	public const HOUR = 7;

	/**
	 * The app config key holding the day (Y-m-d) the last digest went out.
	 */
	public const SENT_KEY = 'submit_failure_digest_day';

	/**
	 * The template a portal may write its own text for.
	 */
	public const TEMPLATE = 'form-failure-digest';

	/**
	 * The group whose members get the digest of a portal that names no `adminGroups`.
	 */
	private const INSTANCE_ADMINS = 'admin';

	/**
	 * Constructor.
	 *
	 * @param SubmitFailureLog          $failures  The failed submits.
	 * @param PortalResolverInterface   $portals   The published portals.
	 * @param IGroupManager             $groups    Who administers a portal.
	 * @param IAppConfig                $appConfig The day the last digest went out.
	 * @param ITimeFactory              $time      The clock.
	 * @param IMailer                   $mailer    Sends the mail.
	 * @param IFactory                  $l10n      The portal's language.
	 * @param LoggerInterface           $logger    Logs a mail that was not sent.
	 * @param MailTemplateRenderer|null $renderer  Swaps in a portal's own text.
	 */
	public function __construct(
		private readonly SubmitFailureLog $failures,
		private readonly PortalResolverInterface $portals,
		private readonly IGroupManager $groups,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly IMailer $mailer,
		private readonly IFactory $l10n,
		private readonly LoggerInterface $logger,
		private readonly ?MailTemplateRenderer $renderer = null,
	) {
	}//end __construct()

	/**
	 * Send today's digest when it is due: from 07:00, once a day, and only
	 * to the portals with failed submits in the past 24 hours.
	 *
	 * @return int The number of mails the mail server took.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public function run(): int {
		$now   = $this->time->getTime();
		$local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get()));
		$today = $local->format('Y-m-d');
		if ((int)$local->format('G') < self::HOUR
			|| $this->appConfig->getValueString(AppIdentity::APP_ID, self::SENT_KEY, '') === $today
		) {
			return 0;
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, self::SENT_KEY, $today);
		$perPortal = self::perPortal(failures: $this->failures->since(from: $now - 86400));
		if ($perPortal === []) {
			return 0;
		}

		$sent  = 0;
		$known = $this->publishedPortals();
		foreach ($perPortal as $slug => $forms) {
			$portal = ($known[$slug] ?? ['slug' => $slug]);
			foreach ($this->addresses(portal: $portal) as $address) {
				$sent += (int)$this->send(address: $address, portal: $portal, forms: $forms);
			}
		}

		return $sent;
	}//end run()

	/**
	 * The failures grouped per portal and per form, in the order they came.
	 *
	 * @param array<int, array{code: string, portal: string, route: string, title: string, status: int, at: int}> $failures The failures.
	 *
	 * @return array<string, array<string, array{title: string, codes: array<int, string>}>> Per portal slug, per route.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public static function perPortal(array $failures): array {
		$out = [];
		foreach ($failures as $failure) {
			$slug  = $failure['portal'];
			$route = $failure['route'];
			if (isset($out[$slug][$route]) === false) {
				$out[$slug][$route] = ['title' => ($failure['title'] !== '' ? $failure['title'] : $route), 'codes' => []];
			}

			$out[$slug][$route]['codes'][] = $failure['code'];
		}

		return $out;
	}//end perPortal()

	/**
	 * One line per form: its name, its address, how often it failed and the codes.
	 *
	 * @param array<string, array{title: string, codes: array<int, string>}> $forms Per route.
	 *
	 * @return array<int, string> The lines.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public static function lines(array $forms): array {
		$lines = [];
		foreach ($forms as $route => $form) {
			$lines[] = sprintf('%s (%s): %d × %s', $form['title'], $route, count($form['codes']), implode(', ', $form['codes']));
		}

		return $lines;
	}//end lines()

	/**
	 * The published portals by slug; none when they cannot be read.
	 *
	 * @return array<string, array<string, mixed>> The portals.
	 */
	private function publishedPortals(): array {
		try {
			$portals = $this->portals->allPublishedPortals();
		} catch (Throwable) {
			return [];
		}

		$out = [];
		foreach ($portals as $portal) {
			if (is_array($portal) === true && is_string($portal['slug'] ?? null) === true) {
				$out[$portal['slug']] = $portal;
			}
		}

		return $out;
	}//end publishedPortals()

	/**
	 * The e-mail addresses of the portal's administrators: the members of its
	 * `adminGroups`, or of the instance administrators when it names none.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return array<int, string> The addresses, once each.
	 */
	private function addresses(array $portal): array {
		$groups = PortalAdminScope::adminGroupsOf(portal: $portal);
		if ($groups === []) {
			$groups = [self::INSTANCE_ADMINS];
		}

		$addresses = [];
		foreach ($groups as $gid) {
			$group = $this->groups->get($gid);
			if ($group === null) {
				continue;
			}

			foreach ($group->getUsers() as $user) {
				$address = trim((string)$user->getEMailAddress());
				if ($address !== '') {
					$addresses[$address] = true;
				}
			}
		}

		return array_keys($addresses);
	}//end addresses()

	/**
	 * Write and send one digest; a failure is logged without the recipient.
	 *
	 * @param string                                                          $address The address.
	 * @param array<string, mixed>                                            $portal  The portal.
	 * @param array<string, array{title: string, codes: array<int, string>}> $forms   The failed forms.
	 *
	 * @return bool True when the mail server took it.
	 */
	private function send(string $address, array $portal, array $forms): bool {
		$name  = trim((string)($portal['title'] ?? $portal['slug'] ?? ''));
		$count = (string)array_sum(array_map(static fn (array $form): int => count($form['codes']), $forms));
		$l     = $this->l10n->get(AppIdentity::APP_ID, (string)(((array)($portal['locales'] ?? []))[0] ?? 'nl'));
		$text  = [
			'subject' => $l->t('Form submits that failed: %1$s', [$name]),
			'body' => $l->t(
				'%1$s submits on %2$s did not reach their destination in the past 24 hours. The residents kept their answers and saw the error code.',
				[$count, $name]
			),
		];
		if ($this->renderer !== null) {
			$text = $this->renderer->render(
				portal: (string)($portal['slug'] ?? ''),
				key: self::TEMPLATE,
				values: ['portal' => $name, 'count' => $count],
				subject: $text['subject'],
				body: $text['body']
			);
		}

		try {
			$mail = $this->mailer->createEMailTemplate('portaliq.form.failuredigest', []);
			$mail->setSubject($text['subject']);
			$mail->addHeader();
			$mail->addHeading($l->t('Form submits that failed'));
			$mail->addBodyText($text['body']);
			foreach (self::lines(forms: $forms) as $line) {
				$mail->addBodyText($line);
			}

			$mail->addFooter();
			$message = $this->mailer->createMessage();
			$message->setTo([$address]);
			$message->useTemplate($mail);

			return (count($this->mailer->send($message)) === 0);
		} catch (Throwable $failure) {
			// The exception's own message is left out: a transport error may quote the recipient.
			$this->logger->warning('Portaliq: form failure digest not sent', ['exception' => get_class($failure), 'app' => AppIdentity::APP_ID]);
			return false;
		}
	}//end send()
}//end class
