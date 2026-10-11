<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorZendesk\Support;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Renders Zendesk objects as the Markdown documents handed to the KB.
 *
 * Every renderer is a pure function of the API payloads it receives, so the
 * content fingerprint computed by the importer is stable across runs.
 */
final class Markdown
{
    public static function html(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        return trim((new HtmlConverter(['strip_tags' => true, 'remove_nodes' => 'script style iframe']))->convert($html));
    }

    /**
     * Body of a comment or an article: prefer the plain text Zendesk already
     * extracted, fall back to converting the HTML.
     *
     * @param  array<string|int,mixed>  $item
     */
    public static function body(array $item): string
    {
        $plain = $item['plain_body'] ?? null;
        if (is_string($plain) && trim($plain) !== '') {
            return trim($plain);
        }
        $html = $item['html_body'] ?? null;
        if (is_string($html) && trim($html) !== '') {
            return self::html($html);
        }
        $body = $item['body'] ?? $item['description'] ?? '';

        return is_string($body) ? (str_contains($body, '<') ? self::html($body) : trim($body)) : '';
    }

    /**
     * @param  array<string|int,mixed>  $ticket
     * @param  array<int,array<string|int,mixed>>  $comments  Already filtered for visibility.
     * @param  array<int,string>  $names  user id → display name, for authors/requester/assignee.
     * @param  bool  $descriptionFallback  Render `description` when no comment is left; false when private comments are hidden.
     */
    public static function ticket(array $ticket, array $comments, array $names = [], ?string $organization = null, bool $descriptionFallback = true): string
    {
        $lines = ['# '.self::oneLine((string) ($ticket['subject'] ?? 'Ticket #'.($ticket['id'] ?? ''))), ''];
        $facts = array_filter([
            'Ticket' => isset($ticket['id']) ? '#'.$ticket['id'] : null,
            'Status' => $ticket['status'] ?? null,
            'Priority' => $ticket['priority'] ?? null,
            'Type' => $ticket['type'] ?? null,
            'Requester' => self::person($ticket['requester_id'] ?? null, $names),
            'Assignee' => self::person($ticket['assignee_id'] ?? null, $names),
            'Organization' => $organization,
            'Channel' => $ticket['via']['channel'] ?? null,
            'Tags' => ($ticket['tags'] ?? []) === [] ? null : implode(', ', array_map('strval', (array) $ticket['tags'])),
            'Created' => $ticket['created_at'] ?? null,
            'Updated' => $ticket['updated_at'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
        foreach ($facts as $label => $value) {
            $lines[] = '- **'.$label.':** '.self::oneLine((string) $value);
        }
        // The description IS the first comment: with internal notes switched off and every comment filtered out,
        // that first comment was private, so it must not reappear here.
        if ($comments === [] && $descriptionFallback) {
            $lines[] = '';
            $lines[] = '## Description';
            $lines[] = '';
            $lines[] = self::body($ticket);
        }
        foreach ($comments as $comment) {
            $internal = ($comment['public'] ?? true) === false;
            $lines[] = '';
            $lines[] = '## '.($internal ? 'Internal note' : 'Comment').' · '.($comment['created_at'] ?? '').self::suffix(self::person($comment['author_id'] ?? null, $names));
            $lines[] = '';
            $lines[] = self::body($comment);
            $files = array_values(array_filter((array) ($comment['attachments'] ?? []), static fn ($file) => is_array($file) && ! ($file['inline'] ?? false)));
            if ($files !== []) {
                $lines[] = '';
                $lines[] = 'Attachments: '.implode(', ', array_map(static fn ($file) => self::oneLine((string) ($file['file_name'] ?? 'file')), $files));
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string|int,mixed>  $user
     */
    public static function user(array $user, ?string $organization = null): string
    {
        $lines = ['# '.self::oneLine((string) ($user['name'] ?? 'Customer #'.($user['id'] ?? ''))), ''];
        $facts = array_filter([
            'Customer' => isset($user['id']) ? '#'.$user['id'] : null,
            'Email' => $user['email'] ?? null,
            'Role' => $user['role'] ?? null,
            'Organization' => $organization,
            'Phone' => $user['phone'] ?? null,
            'Time zone' => $user['time_zone'] ?? null,
            'Locale' => $user['locale'] ?? null,
            'External ID' => $user['external_id'] ?? null,
            'Tags' => ($user['tags'] ?? []) === [] ? null : implode(', ', array_map('strval', (array) $user['tags'])),
            'Suspended' => ($user['suspended'] ?? false) ? 'yes' : null,
            'Created' => $user['created_at'] ?? null,
            'Updated' => $user['updated_at'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
        foreach ($facts as $label => $value) {
            $lines[] = '- **'.$label.':** '.self::oneLine((string) $value);
        }
        foreach (['details' => 'Details', 'notes' => 'Notes'] as $key => $heading) {
            if (is_string($user[$key] ?? null) && trim($user[$key]) !== '') {
                $lines[] = '';
                $lines[] = '## '.$heading;
                $lines[] = '';
                $lines[] = trim($user[$key]);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string|int,mixed>  $organization
     */
    public static function organization(array $organization): string
    {
        $lines = ['# '.self::oneLine((string) ($organization['name'] ?? 'Organization #'.($organization['id'] ?? ''))), ''];
        $facts = array_filter([
            'Organization' => isset($organization['id']) ? '#'.$organization['id'] : null,
            'Domains' => ($organization['domain_names'] ?? []) === [] ? null : implode(', ', array_map('strval', (array) $organization['domain_names'])),
            'External ID' => $organization['external_id'] ?? null,
            'Tags' => ($organization['tags'] ?? []) === [] ? null : implode(', ', array_map('strval', (array) $organization['tags'])),
            'Created' => $organization['created_at'] ?? null,
            'Updated' => $organization['updated_at'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
        foreach ($facts as $label => $value) {
            $lines[] = '- **'.$label.':** '.self::oneLine((string) $value);
        }
        foreach (['details' => 'Details', 'notes' => 'Notes'] as $key => $heading) {
            if (is_string($organization[$key] ?? null) && trim($organization[$key]) !== '') {
                $lines[] = '';
                $lines[] = '## '.$heading;
                $lines[] = '';
                $lines[] = trim($organization[$key]);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string|int,mixed>  $article
     */
    public static function article(array $article): string
    {
        $title = self::oneLine((string) ($article['title'] ?? 'Article'));
        $labels = (array) ($article['label_names'] ?? []);
        $header = '# '.$title."\n\n";
        if ($labels !== []) {
            $header .= '- **Labels:** '.implode(', ', array_map('strval', $labels))."\n\n";
        }

        return $header.self::html((string) ($article['body'] ?? ''))."\n";
    }

    /**
     * @param  array<int,string>  $names
     */
    private static function person(mixed $id, array $names): ?string
    {
        if ($id === null) {
            return null;
        }
        $name = $names[(int) $id] ?? null;

        return $name !== null ? $name.' (#'.(int) $id.')' : '#'.(int) $id;
    }

    private static function suffix(?string $author): string
    {
        return $author === null ? '' : ' · '.$author;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
