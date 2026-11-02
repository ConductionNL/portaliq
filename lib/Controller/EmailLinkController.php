<?php

/**
 * Portaliq Email Link Controller
 *
 * The e-mail link sign-in (sign-in-with-an-email-link, decision 127): ask for
 * a link, read what a link is before pressing the button, and spend it for a
 * fresh `low` session. Behind the instance switch, which is OFF by default:
 * while it is off every route answers like a portal that does not offer the
 * mode. Anonymous by design, so it does not carry the PortalProtected marker.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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

namespace OCA\Portaliq\Controller;

use DateTime;
use DateTimeImmutable;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\BackgroundJob\EmailLinkRequestJob;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkAddress;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkEligibility;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkLimits;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSetting;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Requests, describes and redeems e-mail links.
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#5
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one dependency per check the
 * security review asks for; a facade would hide which one refused.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  -- see above.
 */
class EmailLinkController extends Controller {
	/**
	 * The short-lived cookie that binds a link to the browser that asked (M3).
	 */
	public const COOKIE = 'portaliq_email_link';

	/**
	 * How long the page-fetched value of the link page lives, in seconds.
	 */
	private const NONCE_TTL = 900;

	/**
	 * The cache the page-fetched values live in.
	 *
	 * @var ICache
	 */
	private ICache $nonces;

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request      The request.
	 * @param PortalResolver       $portals      Resolves the portal being visited.
	 * @param EmailLinkSetting     $setting      The switch and the portal's modes.
	 * @param EmailLinkAddress     $address      Normalises and hashes the address.
	 * @param EmailLinkLimits      $limits       The per-address counter.
	 * @param EmailLinkTokens      $tokens       Reads and spends the link.
	 * @param EmailLinkEligibility $eligibility  Checks the account again at redeem.
	 * @param IJobList             $jobs         Queues the lookup and the mail.
	 * @param ISecureRandom        $random       Mints the cookie and the page value.
	 * @param ICacheFactory        $cacheFactory Keeps the page value.
	 * @param PortalSessionService $sessions     Mints the `low` session.
	 * @param PortalIdentityMailer $mailer       Mails the "you signed in" notice.
	 * @param LoggerInterface      $logger       Logs by account and address hash.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly EmailLinkSetting $setting,
		private readonly EmailLinkAddress $address,
		private readonly EmailLinkLimits $limits,
		private readonly EmailLinkTokens $tokens,
		private readonly EmailLinkEligibility $eligibility,
		private readonly IJobList $jobs,
		private readonly ISecureRandom $random,
		ICacheFactory $cacheFactory,
		private readonly PortalSessionService $sessions,
		private readonly PortalIdentityMailer $mailer,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->nonces = $cacheFactory->createDistributed('portaliq-email-link');
	}//end __construct()

	/**
	 * Ask for a link. Every accepted request gets the same answer and queues
	 * one job, known address or not (M1); over the per-address limit the
	 * answer stays the same and nothing is queued (M5).
	 *
	 * @param string $email The address as typed.
	 *
	 * @return JSONResponse `{sent: true}`, 404 when the portal does not offer the mode, 400 for a malformed address.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-reveals-nothing-about-accounts-req-iwi-007
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-is-rate-limited-per-mailbox-per-client-and-per-portal-req-iwi-008
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 3600)]
	#[UserRateLimit(limit: 20, period: 3600)]
	public function request(string $email = ''): JSONResponse {
		$site = $this->site();
		if ($this->setting->offeredBy(portal: $site) === false) {
			// The same shape as an unknown portal: which modes a portal offers
			// is not for an anonymous prober to list.
			return new JSONResponse(['error' => 'mode_not_offered'], Http::STATUS_NOT_FOUND);
		}

		$normalised = $this->address->normalise(address: $email);
		if ($normalised === '' || filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
			return new JSONResponse(['error' => 'invalid_email'], Http::STATUS_BAD_REQUEST);
		}

		$answer      = new JSONResponse(['sent' => true]);
		$addressHash = $this->address->hash(address: $normalised);
		if ($this->limits->countAddress(addressHash: $addressHash) === false) {
			$this->logger->info('Portaliq: e-mail link request over the per-address limit', ['addressHash' => $addressHash]);
			return $answer;
		}

		$cookie = $this->random->generate(32, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		$this->jobs->add(
			EmailLinkRequestJob::class,
			['portal' => (string)($site['slug'] ?? ''), 'address' => $normalised, 'cookieHash' => hash('sha256', $cookie)]
		);
		$answer->addCookie(self::COOKIE, $cookie, new DateTime('+15 minutes'), 'Strict');

		return $answer;
	}//end request()

	/**
	 * What a link is, before the button: the portal, the masked address of the
	 * account, whether this is the browser that asked, and the value the
	 * button's POST must carry. Spends nothing.
	 *
	 * @param string $token The token from the fragment.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-a-link-opened-in-another-browser-asks-for-the-address-first-req-iwi-010
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 300)]
	public function describe(string $token = ''): JSONResponse {
		$peek = $this->tokens->peek(token: $token);
		$row  = $peek['row'];
		if ($peek['state'] !== 'live' || $row === null || $this->setting->isEnabled() === false) {
			return $this->refusal(outcome: $peek['state']);
		}

		$account = $this->eligibility->stillEligible(subjectRef: (string)($row['subjectRef'] ?? ''), organisation: (string)($row['organisation'] ?? ''));
		if ($account === null) {
			return $this->refusal(outcome: EmailLinkTokens::NOT_VALID);
		}

		$nonce = $this->random->generate(32, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		$this->nonces->set('nonce/' . hash('sha256', $token), $nonce, self::NONCE_TTL);

		return new JSONResponse([
			'portal'      => $this->portalName(slug: (string)($row['portal'] ?? '')),
			'address'     => $this->address->mask(address: (string)($account['signInAddress'] ?? '')),
			'sameBrowser' => $this->browserHash() !== '' && hash_equals((string)($row['cookieHash'] ?? ''), $this->browserHash()),
			'nonce'       => $nonce,
		]);
	}//end describe()

	/**
	 * Press "Inloggen": spend the link once and mint a fresh `low` session.
	 * A bearer sent along is never read, reused or upgraded (L2).
	 *
	 * @param string $token The token from the fragment.
	 * @param string $nonce The value describe() handed the page.
	 * @param string $email The typed address, in a browser without the cookie.
	 *
	 * @return JSONResponse The bearer and its times, or a refusal.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-token-is-strong-stored-as-a-hash-and-spent-once-req-iwi-009
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-an-e-mail-link-session-is-a-fresh-low-session-that-cannot-raise-itself-req-iwi-011
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 300)]
	#[BruteForceProtection(action: 'portaliq_email_link')]
	public function redeem(string $token = '', string $nonce = '', string $email = ''): JSONResponse {
		$key    = 'nonce/' . hash('sha256', $token);
		$stored = $this->nonces->get($key);
		$known  = (is_string($stored) === true && $nonce !== '' && hash_equals($stored, $nonce) === true);
		if ($this->setting->isEnabled() === false || $token === '' || $known === false) {
			$refused = $this->refusal(outcome: EmailLinkTokens::NOT_VALID);
			$refused->throttle(['action' => 'portaliq_email_link']);
			return $refused;
		}

		$addressHash = '';
		if (trim($email) !== '') {
			$addressHash = $this->address->hash(address: $email);
		}

		$spent = $this->tokens->spend(token: $token, cookieHash: $this->browserHash(), addressHash: $addressHash);
		if ($spent['outcome'] !== EmailLinkTokens::SPENT || $spent['row'] === null) {
			return $this->refusedRedeem(outcome: $spent['outcome'], key: $key);
		}

		$this->nonces->remove($key);

		return $this->signIn(row: $spent['row']);
	}//end redeem()

	/**
	 * The session for a spent link's account, checked once more (L4).
	 *
	 * @param array<string, mixed> $row The spent link.
	 *
	 * @return JSONResponse
	 */
	private function signIn(array $row): JSONResponse {
		$organisation = (string)($row['organisation'] ?? '');
		$account      = $this->eligibility->stillEligible(subjectRef: (string)($row['subjectRef'] ?? ''), organisation: $organisation);
		if ($account === null) {
			// The link is spent either way.
			return $this->refusal(outcome: EmailLinkTokens::NOT_VALID);
		}

		$subjectRef = (string)$account['subjectRef'];
		$audience   = (string)($account['audience'] ?? 'client');
		$session    = $this->sessions->issueSession(
			subjectRef: $subjectRef,
			audience: $audience,
			organisation: $organisation,
			trust: 'low',
			roles: [$audience . ':read'],
			provider: EmailLinkSetting::MODE
		);
		if ($session === null) {
			return new JSONResponse(['error' => 'session_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$this->mailer->sendSignedInNotice(
			email: (string)($account['signInAddress'] ?? ''),
			organisation: $organisation,
			portal: null,
			moment: new DateTimeImmutable()
		);
		$this->logger->info('Portaliq: signed in with an e-mail link', ['subjectRef' => $subjectRef]);

		$answer = new JSONResponse([
			'bearer'        => $session['token'],
			'expiresAt'     => $session['expiresAt'],
			'hardExpiresAt' => $session['hardExpiresAt'],
			'idleTimeout'   => $session['idleTimeout'],
		]);
		$answer->invalidateCookie(self::COOKIE);

		return $answer;
	}//end signIn()

	/**
	 * The answer to a spend that did not go through. A missing address is a
	 * question, not a guess, so it is not throttled; everything else is.
	 *
	 * @param string $outcome The spend's outcome.
	 * @param string $key     The page value's cache key.
	 *
	 * @return JSONResponse
	 */
	private function refusedRedeem(string $outcome, string $key): JSONResponse {
		$refused = $this->refusal(outcome: $outcome);
		if ($outcome === EmailLinkTokens::ADDRESS_NEEDED || $outcome === EmailLinkTokens::BUSY) {
			return $refused;
		}

		if ($outcome !== EmailLinkTokens::ADDRESS_WRONG) {
			$this->nonces->remove($key);
		}

		$refused->throttle(['action' => 'portaliq_email_link']);

		return $refused;
	}//end refusedRedeem()

	/**
	 * One refusal per outcome. Used is told apart (the person asks for a new
	 * link); unknown, expired and voided are one answer.
	 *
	 * @param string $outcome The outcome.
	 *
	 * @return JSONResponse
	 */
	private function refusal(string $outcome): JSONResponse {
		$codes = [
			EmailLinkTokens::USED           => ['link_used', Http::STATUS_FORBIDDEN],
			EmailLinkTokens::ADDRESS_NEEDED => ['address_needed', Http::STATUS_FORBIDDEN],
			EmailLinkTokens::ADDRESS_WRONG  => ['address_wrong', Http::STATUS_FORBIDDEN],
			EmailLinkTokens::BUSY           => ['busy', Http::STATUS_SERVICE_UNAVAILABLE],
		];
		[$error, $status] = ($codes[$outcome] ?? ['link_not_valid', Http::STATUS_FORBIDDEN]);

		return new JSONResponse(['error' => $error], $status);
	}//end refusal()

	/**
	 * SHA-256 of this browser's request cookie, or '' without one.
	 *
	 * @return string
	 */
	private function browserHash(): string {
		$cookie = $this->request->getCookie(self::COOKIE);
		if (is_string($cookie) === false || $cookie === '') {
			return '';
		}

		return hash('sha256', $cookie);
	}//end browserHash()

	/**
	 * The portal's name for the link page.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return string
	 */
	private function portalName(string $slug): string {
		$portal = $this->portals->resolve(request: $this->request, portalSlug: $slug);

		return trim((string)($portal['title'] ?? ''));
	}//end portalName()

	/**
	 * The portal being visited, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function site(): ?array {
		$named = $this->request->getParam('portal', '');
		if (is_string($named) === false) {
			$named = '';
		}

		return $this->portals->resolve(request: $this->request, portalSlug: trim($named));
	}//end site()
}//end class
