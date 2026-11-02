<?php

/**
 * Portaliq Audience Move
 *
 * The rules and the traces of an account taking on the audience of the
 * invitation it redeemed (invitation-joins-an-unbound-account): which
 * audiences an organisation lets an unbound account take on, and what is
 * left behind when one does (an audit row with the old and the new audience,
 * and a notice to the invited address).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use Throwable;

/**
 * Decides and records an audience move.
 *
 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
 */
class AudienceMove {
	/**
	 * The audiences an unbound account may take on when the organisation
	 * names none: a guardian invited by a school.
	 */
	public const DEFAULT_AUDIENCES = ['parent'];

	/**
	 * The organisation override key that names the allowed audiences.
	 */
	public const CONFIG_KEY = 'unboundAudiences';

	/**
	 * The audit verb of a move.
	 */
	private const AUDIT_VERB = 'audience';

	/**
	 * Constructor.
	 *
	 * @param PortalOrganisationConfigService $organisations The organisation's overrides.
	 * @param PortalIdentityMailer            $mailer        Sends the notice.
	 * @param AuditTrailService               $auditor       Records the move.
	 */
	public function __construct(
		private readonly PortalOrganisationConfigService $organisations,
		private readonly PortalIdentityMailer $mailer,
		private readonly AuditTrailService $auditor,
	) {
	}//end __construct()

	/**
	 * The rules an account of this organisation is held to, for a session
	 * of this trust.
	 *
	 * @param string $organisation The tenant slug.
	 * @param mixed  $trust        The redeeming session's trust level.
	 *
	 * @return UnboundAccount
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	public function rules(string $organisation, mixed $trust): UnboundAccount {
		return new UnboundAccount(trust: $trust, audiences: $this->audiences(organisation: $organisation));
	}//end rules()

	/**
	 * The audiences an unbound account of this organisation may take on
	 * (security review L1): the organisation's `unboundAudiences` override,
	 * else `parent`. The company audience is never one of them, and an
	 * override that names nothing usable allows nothing.
	 *
	 * @param string $organisation The tenant slug.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	public function audiences(string $organisation): array {
		try {
			$overrides = ($this->organisations->presentationFor(orgSlug: $organisation)['overrides'] ?? []);
		} catch (Throwable) {
			$overrides = [];
		}

		$declared = ($overrides[self::CONFIG_KEY] ?? null);
		if (is_array($declared) === false) {
			return self::DEFAULT_AUDIENCES;
		}

		$allowed = array_filter(
			$declared,
			static fn (mixed $audience): bool => is_string($audience) === true && trim($audience) !== '' && $audience !== UnboundAccount::BUSINESS_AUDIENCE
		);

		return array_values(array_unique($allowed));
	}//end audiences()

	/**
	 * Leave the traces of a move: an audit row with the old and the new
	 * audience (security review L3), and a notice to the invited address
	 * that its invitation was accepted (security review M1). Neither ever
	 * throws, so the claim stands either way.
	 *
	 * @param array<string, mixed> $account   The account before the move.
	 * @param string               $accountId The account's row identifier.
	 * @param array<string, mixed> $waiting   The waiting account it took over.
	 * @param string               $jti       The redeeming session's token id.
	 * @param DateTimeImmutable    $moment    When it happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	public function record(array $account, string $accountId, array $waiting, string $jti, DateTimeImmutable $moment): void {
		$organisation = (string)($account['organisation'] ?? '');
		$this->auditor->record(
			verb: self::AUDIT_VERB,
			subjectRef: (string)($account['subjectRef'] ?? ''),
			organisation: $organisation,
			register: 'portaliq',
			schema: 'portalAccount',
			id: $accountId,
			jti: $jti,
			detail: ['from' => (string)($account['audience'] ?? ''), 'to' => (string)($waiting['audience'] ?? '')]
		);

		$invited = trim((string)($waiting['email'] ?? ''));
		if ($invited !== '') {
			$this->mailer->sendClaimNotice(email: $invited, organisation: $organisation, moment: $moment);
		}
	}//end record()
}//end class
