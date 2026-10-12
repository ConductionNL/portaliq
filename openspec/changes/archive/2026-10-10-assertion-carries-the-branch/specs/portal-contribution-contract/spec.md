## MODIFIED Requirements

### Requirement: Frozen assertion wire format

The frozen claim set gains one optional `branch` claim: present, after the nine frozen claims and before a declared scope claim, only when the originating session acts for a company branch. No action may declare `branch` as its `scopeClaim`. See the main spec for the full text and the two new scenarios ("A branch session adds the optional branch claim", "An action cannot declare branch as its scope claim").
