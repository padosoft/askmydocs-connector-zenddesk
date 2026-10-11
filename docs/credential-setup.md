# Credential setup

Two ways to authenticate; both end up as one encrypted secret in the `connector-base` vault. Everything else (subdomain, mode, agent email) is non-secret and lives in the installation's `config_json.connection`.

## 1. Pick the identity the connector runs as

Whatever you choose, the connector can read **exactly what that identity can read**. Create a dedicated agent such as `askmydocs@your-company.com` instead of using a personal account:

- it makes the audit trail honest (`askmydocs` appears in Zendesk's logs, not a colleague);
- you can restrict it with a **custom role** (Enterprise plans) or a light-agent seat, and remove it without breaking anyone's login;
- departures of a person do not silently break the integration.

The role needs to **view**: tickets (including private comments if you keep internal notes enabled), end users, organizations and — if you import them — Help Center articles including their user segments. It never needs to edit anything.

## 2a. API token

1. **Admin Center → Apps and integrations → APIs → Zendesk API**.
2. **Settings** tab → ensure **Token access** is on.
3. **Add API token**, give it a description (`AskMyDocs`), **copy the token now** — Zendesk shows it once.
4. Enter in the connector form: subdomain, *Authentication = API token*, the agent's email, the token.

Wire format: HTTP Basic with username `{email_address}/token` and the token as the password — i.e. the credential string `{email_address}/token:{api_token}`. If you ever test with `curl`, use `-u 'agent@example.com/token:YOUR_TOKEN'`.

Zendesk allows many active tokens; use one per integration so you can revoke it alone. Zendesk documents tokens as impersonating the agent and recommends OAuth where possible.

### Rotation

1. Add a new token in Admin Center.
2. Open the installation in AskMyDocs, choose **Re-enter credentials**, paste the new token. The form verifies it *before* replacing the old one, so a typo cannot break a working installation.
3. Delete the old token in Admin Center.

## 2b. OAuth access token

1. **Admin Center → Apps and integrations → APIs → OAuth Clients → Add OAuth client**.
2. Obtain an access token with scope **`read`** through the grant type you prefer.
3. Enter in the form: subdomain, *Authentication = OAuth access token*, the token (the email field is not used).

The package sends `Authorization: Bearer {token}`. It does **not** refresh tokens. If your OAuth client issues expiring tokens, plan to re-enter the credential before expiry; health checks and scans report `Zendesk rejected the credentials` once it lapses.

## 3. What the form verifies

On save the connector calls `GET /api/v2/users/me` and requires:

1. HTTP 200 with a non-null user `id` — an anonymous caller also gets 200, with a null identity, so the status code alone proves nothing;
2. `role` of `agent` or `admin` — an end-user credential cannot read tickets.

Failure leaves the previous secret in place and shows a message that never includes the token or the upstream body.

## 4. Subdomain rules

Enter `company`, `company.zendesk.com` or `https://company.zendesk.com/`; it is normalised to `company`. Anything that is not a single DNS label under `zendesk.com` is rejected — custom host-mapped domains (`support.company.com`) are not supported for the API; use the `*.zendesk.com` subdomain. Sandbox accounts use their own subdomain (for example `company1602799999.zendesk.com`).

## 5. Checklist

- [ ] Dedicated agent created, minimal role
- [ ] Token access enabled; token copied once
- [ ] Subdomain entered without paths
- [ ] Installation bound to the intended KB project
- [ ] A first **Sync now** completed and the counts look plausible
