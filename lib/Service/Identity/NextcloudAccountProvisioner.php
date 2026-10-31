<?php

/**
 * Portaliq Nextcloud Account Provisioner (an-app-provisions-a-nextcloud-account)
 *
 * The `nextcloud` sign-in mode finds a portal account whose `subjectRef` IS
 * the Nextcloud user id, and refuses everyone else. Nothing could create
 * such an account: the provision event makes a pending account under a
 * freshly minted subjectRef, which this mode can never match. So a training
 * participant with a Nextcloud account could not sign in to the portal of
 * the course they were enrolled in (school portal proof, 06 Oct, item 15).
 *
 * This writes that account for an app, server side, once and idempotently:
 * active, its subjectRef the user id, in the organisation of a portal that
 * offers the mode. It never changes an account that exists: one under the
 * same id in another organisation or audience, or not active, is refused,
 * and so is a waiting account for the same address under another
 * subjectRef. Claims stay a separate, explicit step (the claim event).
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
 * @spec openspec/changes/an-app-provisions-a-nextcloud-account/specs/portal-identity-space/spec.md#requirement-an-app-may-provision-an-active-account-for-a-nextcloud-user
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IUserManager;

/**
 * Provisions an active `nextcloud`-mode portal account for an app.
 *
 * @spec openspec/changes/an-app-provisions-a-nextcloud-account/specs/portal-identity-space/spec.md#requirement-an-app-may-provision-an-active-account-for-a-nextcloud-user
 */
class NextcloudAccountProvisioner {
	/**
	 * The register and schema of a portal account.
	 */
	private const REGISTER = 'portaliq';
	private const SCHEMA = 'portalAccount';

	/**
	 * The sign-in mode the account is for.
	 */
	private const MODE = 'nextcloud';

	/**
	 * The status of an account that may sign in.
	 */
	private const STATUS_ACTIVE = 'active';

	/**
	 * The statuses of an account that no longer holds anything.
	 */
	private const CLOSED = ['void', 'removed'];

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader  Looks accounts up.
	 * @param PortalObjectWriter $writer  Writes the new account.
	 * @param IUserManager       $users   Whether the Nextcloud user exists.
	 * @param PortalResolver     $portals The published portals.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly IUserManager $users,
		private readonly PortalResolver $portals,
	) {
	}//end __construct()

	/**
	 * Provision, or find, the active account of a Nextcloud user.
	 *
	 * @param string $appId        The dispatching app.
	 * @param string $uid          The Nextcloud user id; becomes the subjectRef.
	 * @param string $portal       The slug of a published portal that offers the `nextcloud` mode.
	 * @param string $audience     The audience the account belongs to.
	 * @param string $organisation The tenant; must be the portal's.
	 * @param string $email        A contact address, or ''.
	 * @param string $displayName  The name to greet the person by, or ''.
	 *
	 * @return array{subjectRef: string, status: string, isNew: bool}|string The account, or a refusal word:
	 *         `refused`, `unknown_user`, `unknown_portal`, `portal_mismatch`, `mode_not_offered`,
	 *         `conflict`, `not_active` or `unavailable`.
	 *
	 * @spec openspec/changes/an-app-provisions-a-nextcloud-account/specs/portal-identity-space/spec.md#requirement-an-app-may-provision-an-active-account-for-a-nextcloud-user
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one parameter per field
	 * of the account asked for, as on the provision event it answers.
	 */
	public function provision(
		string $appId,
		string $uid,
		string $portal,
		string $audience,
		string $organisation,
		string $email = '',
		string $displayName = '',
	): array|string {
		if (in_array('', [$appId, $uid, $portal, $audience, $organisation], true) === true) {
			return 'refused';
		}

		if ($this->users->userExists($uid) === false) {
			return 'unknown_user';
		}

		$refusal = $this->portalRefusal(slug: $portal, organisation: $organisation);
		if ($refusal !== null) {
			return $refusal;
		}

		$lookup = new PortalAccountLookup(reader: $this->reader);
		$existing = $lookup->bySubjectRef(subjectRef: $uid);
		if ($existing !== null) {
			return $this->existingAnswer(existing: $existing, audience: $audience, organisation: $organisation);
		}

		// An account in this tenant that holds the address (an invitation's
		// waiting account, a citizen who signed in elsewhere) has its own
		// subjectRef. Writing a second account beside it would split the
		// person in two; taking it over would move its claims without the
		// claim step's checks. Either way: refused.
		if ($this->addressHeld(email: $email, organisation: $organisation) === true) {
			return 'conflict';
		}

		return $this->create(
			appId: $appId,
			uid: $uid,
			audience: $audience,
			organisation: $organisation,
			email: $email,
			displayName: $displayName
		);
	}//end provision()

	/**
	 * Whether a live account in the tenant already holds this address.
	 *
	 * Compared without regard to case (security review L4); a withdrawn or
	 * removed account no longer holds anything.
	 *
	 * @param string $email        The address, or ''.
	 * @param string $organisation The tenant.
	 *
	 * @return bool
	 */
	private function addressHeld(string $email, string $organisation): bool {
		if ($email === '') {
			return false;
		}

		foreach (array_unique([$email, strtolower($email)]) as $asked) {
			$rows = $this->reader->readCollection(
				register: self::REGISTER,
				schema: self::SCHEMA,
				scopeField: 'email',
				subjectRef: $asked,
				organisation: $organisation,
				limit: 5
			);
			foreach ($rows as $row) {
				if (strtolower((string)($row['email'] ?? '')) === strtolower($email)
					&& ($row['organisation'] ?? '') === $organisation
					&& in_array(($row['status'] ?? self::STATUS_ACTIVE), self::CLOSED, true) === false
				) {
					return true;
				}
			}
		}

		return false;
	}//end addressHeld()

	/**
	 * Why the portal cannot take this account, or null when it can.
	 *
	 * @param string $slug         The portal's slug.
	 * @param string $organisation The account's tenant.
	 *
	 * @return string|null
	 */
	private function portalRefusal(string $slug, string $organisation): ?string {
		$portal = null;
		foreach ($this->portals->allPublishedPortals() as $row) {
			if (($row['slug'] ?? null) === $slug) {
				$portal = $row;
				break;
			}
		}

		if ($portal === null) {
			return 'unknown_portal';
		}

		if ((string)($portal['organisation'] ?? '') !== $organisation) {
			return 'portal_mismatch';
		}

		if (in_array(self::MODE, (array)($portal['authentication']['modes'] ?? []), true) === false) {
			return 'mode_not_offered';
		}

		return null;
	}//end portalRefusal()

	/**
	 * The answer for an account that already holds this subjectRef.
	 *
	 * The same tenant, the same audience and active: the call is a repeat and
	 * answers the account as it is. Anything else is somebody's account, or
	 * one a clerk closed, and is never changed from here.
	 *
	 * @param array<string, mixed> $existing     The stored account.
	 * @param string               $audience     The audience asked for.
	 * @param string               $organisation The tenant asked for.
	 *
	 * @return array{subjectRef: string, status: string, isNew: bool}|string
	 */
	private function existingAnswer(array $existing, string $audience, string $organisation): array|string {
		if ((string)($existing['organisation'] ?? '') !== $organisation || (string)($existing['audience'] ?? '') !== $audience) {
			return 'conflict';
		}

		$status = (string)($existing['status'] ?? self::STATUS_ACTIVE);
		if ($status !== self::STATUS_ACTIVE) {
			return 'not_active';
		}

		return ['subjectRef' => (string)$existing['subjectRef'], 'status' => $status, 'isNew' => false];
	}//end existingAnswer()

	/**
	 * Write the new, active account.
	 *
	 * @param string $appId        The dispatching app, recorded as the provisioner.
	 * @param string $uid          The Nextcloud user id.
	 * @param string $audience     The audience.
	 * @param string $organisation The tenant.
	 * @param string $email        A contact address, or ''.
	 * @param string $displayName  The name, or ''.
	 *
	 * @return array{subjectRef: string, status: string, isNew: bool}|string
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the fields of the row it writes.
	 */
	private function create(
		string $appId,
		string $uid,
		string $audience,
		string $organisation,
		string $email,
		string $displayName,
	): array|string {
		$data = [
			'subjectRef'    => $uid,
			'audience'      => $audience,
			'organisation'  => $organisation,
			'displayName'   => $displayName,
			'status'        => self::STATUS_ACTIVE,
			'provisionedBy' => $appId,
			'provisionedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];
		if ($email !== '') {
			// Not verified: the app says where to write, not that the person
			// proved the address.
			$data['email'] = $email;
			$data['verifiedEmail'] = false;
		}

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			data: $data
		);
		if ($created === null) {
			return 'unavailable';
		}

		return ['subjectRef' => $uid, 'status' => self::STATUS_ACTIVE, 'isNew' => true];
	}//end create()
}//end class
