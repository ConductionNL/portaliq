<?php

/**
 * Portaliq Email Link Sender
 *
 * The work behind one accepted link request, run by the queued job and never
 * inside the request (security review M1): look the address up, issue and
 * mail a link when exactly one eligible account holds it, or do nothing.
 * Logged by account and address hash only, never the address or the token.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity\EmailLink
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
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#5
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity\EmailLink;

use OCA\Portaliq\Kernel\Contracts\EmailLinkSenderInterface;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\PortalSlugLookup;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use Psr\Log\LoggerInterface;

/**
 * Looks the address up and mails the link, or does nothing.
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#5
 */
class EmailLinkSender implements EmailLinkSenderInterface {
	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface   $reader      Reads the portal.
	 * @param EmailLinkSetting     $setting     The switch and the portal's modes.
	 * @param EmailLinkEligibility $eligibility The one account behind the address.
	 * @param EmailLinkTokens      $tokens      Issues the link.
	 * @param EmailLinkLimits      $limits      The per-portal mail cap.
	 * @param EmailLinkAddress     $address     Hashes the address for the log.
	 * @param PortalIdentityMailer $mailer      Mails the link.
	 * @param LoggerInterface      $logger      Logs by account and address hash.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one dependency per check the request passes.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly EmailLinkSetting $setting,
		private readonly EmailLinkEligibility $eligibility,
		private readonly EmailLinkTokens $tokens,
		private readonly EmailLinkLimits $limits,
		private readonly EmailLinkAddress $address,
		private readonly PortalIdentityMailer $mailer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one accepted request.
	 *
	 * @param string $portalSlug The portal it was asked on.
	 * @param string $address    The address as typed.
	 * @param string $cookieHash SHA-256 of the requesting browser's cookie.
	 *
	 * @return bool True when a link was mailed (for the tests and the log only).
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-reveals-nothing-about-accounts-req-iwi-007
	 */
	public function handle(string $portalSlug, string $address, string $cookieHash): bool {
		$addressHash = $this->address->hash(address: $address);
		$portal      = $this->portal(slug: $portalSlug);
		$account     = $this->eligibleAccount(address: $address, portal: $portal, addressHash: $addressHash);
		if ($account === null) {
			return false;
		}

		$token = $this->issuedToken(account: $account, portalSlug: $portalSlug, cookieHash: $cookieHash, addressHash: $addressHash);
		if ($token === null) {
			return false;
		}

		return $this->mailToken(account: $account, portal: $portal, token: $token, addressHash: $addressHash);
	}//end handle()

	/**
	 * The single account the address may sign in to on this portal, or null with the reason logged.
	 *
	 * @param string                    $address     The address as typed.
	 * @param array<string, mixed>|null $portal      The portal.
	 * @param string                    $addressHash The address's hash, for the log.
	 *
	 * @return array<string, mixed>|null
	 */
	private function eligibleAccount(string $address, array|null $portal, string $addressHash): array|null {
		if ($addressHash === '' || $this->setting->offeredBy(portal: $portal) === false) {
			$this->logger->info('Portaliq: e-mail link not sent, the mode is not offered', ['addressHash' => $addressHash]);
			return null;
		}

		$organisation = (string)($portal['organisation'] ?? '');
		$account      = $this->eligibility->accountFor(address: $address, organisation: $organisation);
		if ($account === null) {
			$this->logger->info('Portaliq: e-mail link not sent, no single eligible account', ['addressHash' => $addressHash]);
			return null;
		}

		return $account;
	}//end eligibleAccount()

	/**
	 * Issue the sign-in token within the portal's hourly cap, or null with the reason logged.
	 *
	 * @param array<string, mixed> $account     The eligible account.
	 * @param string               $portalSlug  The portal it was asked on.
	 * @param string               $cookieHash  SHA-256 of the requesting browser's cookie.
	 * @param string               $addressHash The address's hash.
	 *
	 * @return string|null
	 */
	private function issuedToken(array $account, string $portalSlug, string $cookieHash, string $addressHash): string|null {
		$subjectRef = (string)($account['subjectRef'] ?? '');
		if ($this->limits->countPortalMail(portal: $portalSlug) === false) {
			$this->logger->warning('Portaliq: e-mail link not sent, the portal reached its hourly cap', ['subjectRef' => $subjectRef]);
			return null;
		}

		$token = $this->tokens->issue(account: $account, portal: $portalSlug, cookieHash: $cookieHash, addressHash: $addressHash);
		if ($token === null) {
			$this->logger->warning('Portaliq: e-mail link not issued', ['subjectRef' => $subjectRef]);
			return null;
		}

		return $token;
	}//end issuedToken()

	/**
	 * Mail the sign-in link to the account's sign-in address.
	 *
	 * @param array<string, mixed>      $account     The eligible account.
	 * @param array<string, mixed>|null $portal      The portal.
	 * @param string                    $token       The issued secret.
	 * @param string                    $addressHash The address's hash, for the log.
	 *
	 * @return bool True when a link was mailed.
	 */
	private function mailToken(array $account, array|null $portal, string $token, string $addressHash): bool {
		$signInAddress = (string)($account['signInAddress'] ?? '');
		$sent          = $this->mailer->send(
			template: PortalIdentityMailer::TEMPLATE_EMAIL_LINK,
			email: $signInAddress,
			secret: $token,
			organisation: (string)($portal['organisation'] ?? ''),
			portal: $portal,
			details: ['address' => $signInAddress]
		);
		$subjectRef    = (string)($account['subjectRef'] ?? '');
		$this->logger->info('Portaliq: e-mail link requested', ['subjectRef' => $subjectRef, 'addressHash' => $addressHash, 'mailed' => $sent]);

		return $sent;
	}//end mailToken()

	/**
	 * The portal by its slug, or null.
	 *
	 * @param string $slug The slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function portal(string $slug): ?array {
		return (new PortalSlugLookup(reader: $this->reader))->find(slug: $slug);
	}//end portal()
}//end class
