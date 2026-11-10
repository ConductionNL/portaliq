<?php

/**
 * OpenRegister test stub: a verbatim copy of openregister's FormSubmission on
 * main (openregister #7788 / #7964), loaded only when OpenRegister is absent (tests/bootstrap.php) and
 * read by psalm and phpstan. Only the @spec tags differ.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude test stub of an OpenRegister class
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Service\Object\DraftOwner;
use OCP\IUser;

/**
 * One request to the submit path: what to write, what was filled in, and by whom.
 *
 * @spec exclude test stub of an OpenRegister class
 */
final readonly class FormSubmission {

	/**
	 * The writes, in order: `{ register, schema, mapping?, as? }`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $writes;

	/**
	 * What the submitter filled in.
	 *
	 * @var array<string, mixed>
	 */
	public array $payload;

	/**
	 * The signed-in subject; null for anonymous.
	 *
	 * @var IUser|null
	 */
	public ?IUser $subject;

	/**
	 * The form id the key and upload tokens belong to.
	 *
	 * @var string
	 */
	public string $scope;

	/**
	 * The draft saved into or left, or null.
	 *
	 * @var string|null
	 */
	public ?string $draftId;

	/**
	 * The draft's asserted owner; null takes the subject.
	 *
	 * @var DraftOwner|null
	 */
	public ?DraftOwner $owner;

	/**
	 * Bundle the request.
	 *
	 * @param array<int, array<string, mixed>> $writes    The writes, in order: `{ register, schema, mapping?, as? }`.
	 * @param array<string, mixed>             $payload   What the submitter filled in.
	 * @param FormSubmitter                    $submitter Who submits it and where it lands.
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public function __construct(array $writes, array $payload, FormSubmitter $submitter=new FormSubmitter()) {
		$this->writes  = $writes;
		$this->payload = $payload;
		$this->subject = $submitter->subject;
		$this->scope   = $submitter->scope;
		$this->draftId = $submitter->draftId;
		$this->owner   = $submitter->owner;
	}//end __construct()

	/**
	 * Who the submit is by, as the idempotency binding reads it.
	 *
	 * @return string The user id, a scope value, or the anonymous marker.
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public function submitter(): string {
		if ($this->subject !== null) {
			return 'user:' . $this->subject->getUID();
		}

		if ($this->owner !== null && $this->owner->isScoped() === true) {
			return 'scope:' . $this->owner->value();
		}

		return 'anonymous';
	}//end submitter()

	/**
	 * The scope an idempotency key belongs to: the form, else the first destination.
	 *
	 * @return string The scope.
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public function keyScope(): string {
		if ($this->scope !== '') {
			return $this->scope;
		}

		$first = ($this->writes[0] ?? []);

		return 'destination:' . (string)json_encode([$first['register'] ?? null, $first['schema'] ?? null]);
	}//end keyScope()
}//end class
