<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\Contracts\DeclaresProvenance;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsSourceAcl;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorZendesk\Exceptions\ZendeskApiException;
use Padosoft\AskMyDocsConnectorZendesk\Http\ZendeskClient;
use Padosoft\AskMyDocsConnectorZendesk\Support\RemoteId;
use Padosoft\AskMyDocsConnectorZendesk\Support\SourceAccessPolicy;
use Padosoft\AskMyDocsConnectorZendesk\Sync\SyncManager;

/**
 * The Zendesk connector: credential form, per-installation settings, the
 * scheduler-facing sync entry points, provenance and source-ACL reporting.
 *
 * Import work never runs inside these methods — `syncFull()` and
 * `syncIncremental()` only queue or resume the package's own resumable scan.
 */
final class ZendeskConnector extends BaseConnector implements DeclaresProvenance, SupportsConnectionSettings, SupportsCredentialForm, SupportsSourceAcl
{
    public function key(): string
    {
        return 'zendesk';
    }

    public function displayName(): string
    {
        return 'Zendesk';
    }

    /**
     * @return array<string|int,mixed>
     */
    public function credentialFormSchema(): array
    {
        return [
            (new CredentialField('subdomain', 'Zendesk subdomain', 'text', 'connection', required: true, help: 'For example company or company.zendesk.com'))->toArray(),
            (new CredentialField('auth_mode', 'Authentication', 'select', 'connection', required: true, default: ZendeskClient::AUTH_API_TOKEN,
                options: [ZendeskClient::AUTH_API_TOKEN => 'API token (agent email + token)', ZendeskClient::AUTH_OAUTH_TOKEN => 'OAuth access token'],
                help: 'API tokens are created in Admin Center → Apps and integrations → APIs. An OAuth access token must carry at least the read scope.'))->toArray(),
            (new CredentialField('email', 'Admin email', 'text', 'connection', showIf: ['field' => 'auth_mode', 'equals' => ZendeskClient::AUTH_API_TOKEN],
                help: 'The admin whose identity the API token acts as (incremental exports are admin-only).'))->toArray(),
            (new CredentialField('api_token', 'API token or access token', 'password', 'secret', required: true, secret: true))->toArray(),
        ];
    }

    /**
     * @return array<string|int,mixed>
     */
    public function connectionSettingsSchema(): array
    {
        return [
            (new CredentialField('date_window_days', 'Ticket history (days)', 'number', 'config', default: 90, help: 'Tickets, customers and organizations updated within this period. Use Fetch all for a one-time historical import.', group: 'Sync'))->toArray(),
            (new CredentialField('include_internal_notes', 'Include internal notes', 'checkbox', 'config', default: true, help: 'Private agent comments on tickets.', group: 'Content'))->toArray(),
            (new CredentialField('customers.enabled', 'Import customers (end users)', 'checkbox', 'config', default: true, help: 'One profile document per end user: name, email, organization, tags, details and notes.', group: 'Content'))->toArray(),
            (new CredentialField('organizations.enabled', 'Import organizations', 'checkbox', 'config', default: true, group: 'Content'))->toArray(),
            (new CredentialField('help_center.enabled', 'Import Help Center articles', 'checkbox', 'config', default: true, help: 'Published articles only; drafts are never imported.', group: 'Content'))->toArray(),
            (new CredentialField('help_center.locale', 'Help Center locale', 'text', 'config', default: '', help: 'Leave empty for every locale the agent can read, or set one such as en-us.', group: 'Content'))->toArray(),
            (new CredentialField('attachments.enabled', 'Import attachments', 'checkbox', 'config', default: true, group: 'Attachments'))->toArray(),
            (new CredentialField('attachments.max_size_mb', 'Maximum file size (MiB)', 'number', 'config', default: 25, group: 'Attachments'))->toArray(),
            (new CredentialField('attachments.max_per_ticket', 'Maximum files per ticket', 'number', 'config', default: 20, group: 'Attachments'))->toArray(),
            (new CredentialField('provenance_tier', 'Authorship of imported content', 'select', 'config', default: ProvenanceTier::UntrustedExternal->value,
                options: [ProvenanceTier::UntrustedExternal->value => 'Untrusted external (customers write into tickets)', ProvenanceTier::TrustedInternal->value => 'Trusted internal (staff-authored only, e.g. Help Center)'],
                help: 'Recorded on every document of this installation. Tickets carry text written by customers; keep the default unless this installation imports staff-authored content only.', group: 'Trust'))->toArray(),
        ];
    }

    public function initiateOAuth(int $installationId): string
    {
        $this->loadInstallation($installationId);

        return '/connectors/zendesk/credentials?state='.urlencode($this->issueOAuthState($installationId));
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $installation = $this->loadInstallation($installationId);
        if (! $this->consumeOAuthState($installationId, (string) $request->input('state'))) {
            throw new ConnectorAuthException('Invalid Zendesk credential state.');
        }
        $secret = (string) $request->input('api_token');
        if (trim($secret) === '') {
            throw new ConnectorAuthException('Enter a Zendesk API token or OAuth access token.');
        }
        try {
            $client = new ZendeskClient(
                (string) data_get($installation->config_json, 'connection.subdomain'),
                $secret,
                (string) data_get($installation->config_json, 'connection.auth_mode', ZendeskClient::AUTH_API_TOKEN),
                (string) data_get($installation->config_json, 'connection.email', ''),
            );
            self::verify($client);
        } catch (ConnectorAuthException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ConnectorAuthException($exception->getMessage(), previous: $exception);
        }
        $this->vault->setCredentials($installationId, accessToken: $secret);
        $this->emitAudit('installed', installationId: $installationId);
    }

    /**
     * `GET /users/me` answers 200 for an anonymous caller with `id: null`, so a
     * successful status alone proves nothing; the identity and its role do.
     */
    public static function verify(ZendeskClient $client): void
    {
        $me = $client->get('/users/me')['user'] ?? null;
        if (! is_array($me) || (int) ($me['id'] ?? 0) < 1) {
            throw new ConnectorAuthException('Zendesk did not recognise the credentials.');
        }
        // The import walks the incremental export APIs, which Zendesk restricts to admins: an agent would be
        // accepted here and then fail every scan with a 403.
        if (($me['role'] ?? '') !== 'admin') {
            throw new ConnectorAuthException('Use an admin account: Zendesk restricts the incremental export APIs the import relies on to admins.');
        }
    }

    public function health(int $installationId): HealthStatus
    {
        try {
            self::verify(app(SyncManager::class)->client($this->loadInstallation($installationId)));

            return HealthStatus::healthy();
        } catch (\Throwable $exception) {
            return HealthStatus::errored($exception->getMessage());
        }
    }

    public function syncFull(int $installationId): SyncResult
    {
        return $this->enqueue($installationId);
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        // The package's durable checkpoints are the watermark; $since is deliberately ignored.
        return $this->enqueue($installationId);
    }

    private function enqueue(int $installationId): SyncResult
    {
        $installation = $this->loadInstallation($installationId);
        app(SyncManager::class)->start($installation);

        return new SyncResult(0, 0, 0, [], Carbon::parse($installation->last_sync_at ?? '1970-01-01T00:00:00Z'));
    }

    public function disconnect(int $installationId): void
    {
        $installation = $this->loadInstallation($installationId);
        app(SyncManager::class)->cancel($installation);
        $this->vault->clearCredentials($installationId);
        $this->emitAudit('disconnected', installationId: $installationId);
    }

    /**
     * Side-effect free: reads the stored setting only. Unknown values fail
     * closed to the external tier, mirroring ProvenanceTier::fromStorage().
     */
    public function provenanceTier(int $installationId): ProvenanceTier
    {
        $installation = $this->loadInstallation($installationId);
        $stored = data_get($installation->config_json, 'provenance_tier');

        return is_string($stored) ? (ProvenanceTier::tryFrom($stored) ?? ProvenanceTier::UntrustedExternal) : ProvenanceTier::UntrustedExternal;
    }

    /**
     * `$remoteId` is the `zendesk_remote_id` the importer stored:
     * `{installation}:{kind}:{id}` (see RemoteId). Articles are re-read live
     * because their user segment can change without the body changing.
     */
    public function readAcl(string $remoteId): SourceAccess
    {
        $parts = RemoteId::parse($remoteId);
        $manager = app(SyncManager::class);
        $installation = $manager->installation($parts['installation_id']);
        if (! in_array($parts['kind'], ['article', 'ticket'], true)) {
            return $parts['kind'] === 'attachment' ? SourceAccess::inherited() : SourceAccessPolicy::agentsOnly();
        }
        $client = $manager->client($installation);
        try {
            if ($parts['kind'] === 'article') {
                $article = $client->get('/help_center/articles/'.$parts['remote_id'])['article'] ?? null;

                return is_array($article) ? SourceAccessPolicy::article($article) : SourceAccess::unknown();
            }
            // Existence check only: a ticket the credentials can no longer read must not be reported as agent-readable.
            $ticket = $client->get('/tickets/'.$parts['remote_id'])['ticket'] ?? null;

            return is_array($ticket) ? SourceAccessPolicy::agentsOnly() : SourceAccess::unknown();
        } catch (ZendeskApiException $exception) {
            if (in_array($exception->status, [401, 403], true)) {
                throw new ConnectorAuthException($exception->getMessage(), previous: $exception);
            }

            return SourceAccess::unknown();
        }
    }
}
