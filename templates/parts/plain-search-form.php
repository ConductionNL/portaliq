<?php
// SPDX-License-Identifier: EUPL-1.2
//
// THE GET SEARCH FORM OF A PLAIN PAGE BLOCK, shared by the publication search
// and the public records list on `site-plain.php` (site-honest-without-
// javascript REQ-SHJ-003, site-member-voting-record-and-confidential-papers
// REQ-SCR-006). It reads `$block` (`action`, `route`, `portal`, `label`,
// `query`, `submit`) and `$searchId`, the id of the field, from the including
// template; the form submits `route`, `portal` and `_search` to the plain page.

declare(strict_types=1);

$searchAction = (string)$block['action'];
$searchPortal = (string)($block['portal'] ?? '');
$searchSubmit = (string)$block['submit'];
?>
<form method="get" action="<?php p($searchAction); ?>" role="search">
    <input type="hidden" name="route" value="<?php p((string)$block['route']); ?>">
    <?php if ($searchPortal !== '') { ?>
    <input type="hidden" name="portal" value="<?php p($searchPortal); ?>">
    <?php } ?>
    <label class="utrecht-form-label" for="<?php p($searchId); ?>">
        <?php p((string)$block['label']); ?>
    </label>
    <input id="<?php p($searchId); ?>"
           class="utrecht-textbox utrecht-textbox--html-input"
           type="search" name="_search" value="<?php p((string)$block['query']); ?>">
    <button type="submit" class="utrecht-button utrecht-button--primary-action"
            aria-label="<?php p($searchSubmit); ?>"><?php p($searchSubmit); ?></button>
</form>
