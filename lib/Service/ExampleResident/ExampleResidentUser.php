<?php

/**
 * Portaliq Example Resident User
 *
 * The Nextcloud account the example resident signs in with. The sign-in mode
 * `nextcloud` leaves the password to Nextcloud's own form, so the example
 * resident needs an account there and nothing else: no test door, no broker.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\ExampleResident
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
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\ExampleResident;

use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates and deletes the example resident's Nextcloud account.
 *
 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
 */
class ExampleResidentUser {
	/**
	 * How long a password this class makes is.
	 */
	private const PASSWORD_LENGTH = 24;

	/**
	 * Constructor.
	 *
	 * @param IUserManager    $users  Nextcloud's accounts.
	 * @param ISecureRandom   $random Makes the password when the administrator gives none.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserManager $users,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a Nextcloud account with this id exists.
	 *
	 * @param string $userId The account's id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
	 */
	public function exists(string $userId): bool {
		return $this->users->userExists($userId) === true;
	}//end exists()

	/**
	 * A password nobody has seen yet.
	 *
	 * Letters and digits without the ones that read alike, and one fixed
	 * ending so a password policy that asks for a capital, a digit and a
	 * special character is met.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
	 */
	public function newPassword(): string {
		return $this->random->generate(self::PASSWORD_LENGTH, ISecureRandom::CHAR_HUMAN_READABLE) . '-Zd7!';
	}//end newPassword()

	/**
	 * Create the account.
	 *
	 * @param string $userId      The account's id.
	 * @param string $displayName The name Nextcloud shows.
	 * @param string $password    The password.
	 *
	 * @return string '' when the account exists afterwards, else why it does not.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-the-resident-must-sign-in-without-a-test-door
	 */
	public function create(string $userId, string $displayName, string $password): string {
		try {
			$user = $this->users->createUser($userId, $password);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the example resident account was not created', ['reason' => $e->getMessage()]);
			return $e->getMessage();
		}

		if ($user === false || $user === null) {
			return 'Nextcloud refused the account';
		}

		$user->setDisplayName($displayName);

		return '';
	}//end create()

	/**
	 * Delete the account.
	 *
	 * @param string $userId The account's id.
	 *
	 * @return bool True when no account with this id is left.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	public function delete(string $userId): bool {
		$user = $this->users->get($userId);
		if ($user === null) {
			return true;
		}

		return $user->delete() === true;
	}//end delete()
}//end class
