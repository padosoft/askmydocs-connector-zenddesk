<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Exceptions;

use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;

/**
 * A non-2xx answer from the Zendesk API, reduced to what a caller may act on.
 *
 * The upstream body is never carried in the message: Zendesk error payloads
 * echo request parameters, and a 401 body can quote the credential format.
 * Only the status, the Retry-After hint and a sanitised list of offending
 * field names survive.
 */
final class ZendeskApiException extends ConnectorApiException
{
    /** @param list<array{field:string,code:string}> $validationFields */
    public function __construct(public readonly int $status, public readonly int $retryAfter = 0, public readonly array $validationFields = [])
    {
        parent::__construct(match ($status) {
            401 => 'Zendesk rejected the credentials.',
            403 => 'The Zendesk account does not permit this operation.',
            404 => 'Zendesk resource not found.',
            422 => 'Zendesk rejected the request parameters.',
            429 => 'Zendesk rate limit reached. The import will resume after Retry-After.',
            default => 'Zendesk request failed (HTTP '.$status.').',
        });
    }

    /** @return array<string,mixed> */
    public function diagnostics(): array
    {
        return [
            'error_code' => match ($this->status) {
                400, 422 => 'invalid_query',
                401 => 'authentication_failed',
                403 => 'permission_denied',
                404 => 'resource_not_found',
                429 => 'rate_limited',
                default => 'request_failed',
            },
            'http_status' => $this->status,
            'retryable' => $this->status === 429 || $this->status >= 500,
            'validation_fields' => $this->validationFields,
        ];
    }
}
