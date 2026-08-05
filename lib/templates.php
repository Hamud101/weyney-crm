<?php
/**
 * Email templates: a subject, a short body, and the PDF that body introduces.
 *
 * The PDFs live in ../attachments and are built from the print sources in
 * attachments/src (see build.py there) — same document system as the client
 * proposals, so what a prospect receives looks like everything else we send.
 *
 * Adding one: drop the PDF in attachments/, add an entry here. Nothing else in
 * the app needs to change; the front end lists whatever this returns.
 *
 * Bodies are plain text. They are a starting point, not a script — the sheet
 * fills them in and the sender edits before it goes.
 */

/** Placeholders any template body may use. Kept small on purpose: every one of
 *  these comes off the lead record, so nothing is ever invented for a prospect. */
function tpl_fields(array $lead): array {
    $contact = trim((string)($lead['contact'] ?? ''));
    // "Dana Whitfield (owner)" → "Dana". No contact on file → "there", which
    // reads fine as "Hi there,".
    $first = $contact !== '' ? preg_split('/\s+/', $contact)[0] : '';
    return [
        '{{first_name}}' => $first !== '' ? $first : 'there',
        '{{company}}'    => trim((string)($lead['name'] ?? '')) ?: 'your business',
    ];
}

function email_templates(): array {
    return [
        'core-services' => [
            'name'    => 'Core services',
            'blurb'   => 'Introduces the three services, pricing and how a project runs.',
            'subject' => 'What I do — a quick overview',
            'body'    =>
                "Hi {{first_name}},\n\n" .
                "I've attached a short overview of what I do — custom websites, ranking " .
                "optimization and marketing automation — along with what each costs and " .
                "how a project usually runs.\n\n" .
                "It's a two-page read. If any of it looks useful for {{company}}, tell me " .
                "a time that suits you and I'll give you a call.\n\n" .
                "Thanks,\nHamud",
            'file'    => 'weyney-core-services.pdf',
            // ASCII only: non-ASCII in a filename parameter needs RFC 2231
            // encoding, and not every mail client handles it the same way.
            'as'      => 'Weyney Media - Core Services.pdf',
        ],
        'website-benefits' => [
            'name'    => 'Why a website matters',
            'blurb'   => 'The case for having a proper site, and where my services fit.',
            'subject' => 'The customers {{company}} never hears from',
            'body'    =>
                "Hi {{first_name}},\n\n" .
                "I've attached a short piece on what a website actually does for a business " .
                "like {{company}} — where customers look before they call, what a site does " .
                "that a social page can't, and what separates one that brings in work from " .
                "one that just sits there.\n\n" .
                "The last page covers how I'd help. If it's worth a conversation, tell me a " .
                "time that suits you and I'll call you.\n\n" .
                "Thanks,\nHamud",
            'file'    => 'weyney-website-benefits.pdf',
            'as'      => 'Weyney Media - Why Your Business Needs a Website.pdf',
        ],
        'local-growth' => [
            'name'    => 'Getting found locally',
            'blurb'   => 'Local search, reviews and instant follow-up — with setup steps and pricing.',
            'subject' => 'Getting {{company}} found locally',
            'body'    =>
                "Hi {{first_name}},\n\n" .
                "I've attached a short piece on the work that happens after a website is " .
                "live — getting {{company}} into the map results people actually call from, " .
                "keeping reviews coming in without chasing anyone, and answering a new " .
                "enquiry in about a minute instead of the next morning.\n\n" .
                "It covers what setting it up involves, what I'd need from you, and what it " .
                "costs. The audit at the start is free either way — tell me a time that " .
                "suits you and I'll call you.\n\n" .
                "Thanks,\nHamud",
            'file'    => 'weyney-local-growth.pdf',
            'as'      => 'Weyney Media - Getting Found Locally.pdf',
        ],
    ];
}

function tpl_attachment_dir(): string {
    return dirname(__DIR__) . '/attachments';
}

/** One template with its placeholders filled from the lead, ready for the sheet. */
function tpl_render(string $id, array $lead): ?array {
    $all = email_templates();
    if (!isset($all[$id])) return null;
    $t = $all[$id];
    $f = tpl_fields($lead);

    return [
        'id'         => $id,
        'name'       => $t['name'],
        'blurb'      => $t['blurb'],
        'subject'    => strtr($t['subject'], $f),
        'body'       => strtr($t['body'], $f),
        'attachment' => $t['as'],
        // A template whose PDF hasn't been deployed is worse than no template:
        // it fails at the moment someone hits Send.
        'ready'      => is_readable(tpl_attachment_dir() . '/' . $t['file']),
    ];
}

/** Every template, rendered for this lead. Order is the order they appear in. */
function tpl_render_all(array $lead): array {
    $out = [];
    foreach (array_keys(email_templates()) as $id) $out[] = tpl_render($id, $lead);
    return $out;
}

/** The attachment list send_mail() wants, or [] for an unknown template. */
function tpl_attachments(string $id): array {
    $all = email_templates();
    if (!isset($all[$id])) return [];
    $t = $all[$id];
    return [[
        'path' => tpl_attachment_dir() . '/' . $t['file'],
        'name' => $t['as'],
        'type' => 'application/pdf',
    ]];
}
