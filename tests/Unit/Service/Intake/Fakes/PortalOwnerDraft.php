<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake\Fakes;

use OCA\Portaliq\Service\Intake\DestinationDraft;

/**
 * DestinationDraft with a draft owner that needs no OpenRegister: `{value: portal:<ref>}`.
 *
 * The real owner is OpenRegister's DraftOwner; CI runs without OpenRegister,
 * so the draft paths are tested against this stand-in and the real factory
 * once, where the class is there (DestinationDraftTest).
 */
class PortalOwnerDraft extends DestinationDraft {
	/**
	 * The stand-in owner, or null for an empty reference.
	 *
	 * @param string $subjectRef The subject.
	 *
	 * @return object|null
	 */
	protected function ownerOf(string $subjectRef): ?object {
		if ($subjectRef === '') {
			return null;
		}

		return (object)['value' => 'portal:' . $subjectRef];
	}//end ownerOf()
}//end class
