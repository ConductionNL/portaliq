# The assertion carries the session's branch

## Why

A company that logs in for one of its branches (signin-eherkenning-branch) gets a session with a `branch` claim, but the `X-Portal-Subject` assertion that portaliq forwards to a domain app dropped it, because the frozen wire format allowed nothing beyond the nine claims and one declared scope claim. dossiq stamps the branch on a Woo request it receives (portal-case-list-declarations 3.2), so without the claim a branch session's Woo requests stay invisible to that branch. A scope claim cannot carry it: it refuses the action when the account lacks the value, and a session without a branch must still be able to act.

Ruben decided on 10 Oct (decision 173, Q-dossiq-L2-5): the frozen format gains one optional `branch` claim, with its pin test.

## What changes

- `PortalJwtService::createAssertion()` takes the session's branch and adds `branch` after the nine frozen claims, only when it is not empty.
- `PortalSessionService::issueAssertion()` passes the resolved subject's branch.
- `branch` becomes a reserved scope claim name, so a manifest cannot forge it.
- The "Frozen assertion wire format" requirement says so, with two new scenarios.

## Impact

Receivers templated against the nine claims ignore unknown claims (dossiq, filinq and the other receivers in the fleet decode the payload and read named keys). A session without a branch mints exactly the bytes it did before.
