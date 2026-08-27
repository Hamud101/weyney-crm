<?php
/**
 * Template for secrets.php.
 *
 * The real file does NOT belong here. It lives in the data directory outside the
 * web root, at ~/appdata/crm/secrets.php, so the web server will never serve it.
 * lib/config.php is the only thing that reads it.
 *
 *     mkdir -p ~/appdata/crm
 *     cp lib/secrets.example.php ~/appdata/crm/secrets.php
 *
 * Then fill in the values below. Anything you leave out falls back to the
 * default passed to cfg(), so you can start with just a login hash and add the
 * mail and Google settings when you need them.
 */

return [
    'timezone' => 'America/Chicago',

    // Single-user login. Generate the hash, never store the password itself:
    //   php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
    'login_password_hash' => '',

    // Outbound mail.
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 587,
    'smtp_user' => 'you@example.com',
    'smtp_pass' => '',
    'smtp_from' => 'you@example.com',

    // Where sent mail gets filed after SMTP hands it off, so the thread reads
    // normally in a regular mail client. imap_sent is the folder name as your
    // provider spells it, which is often 'INBOX.Sent' rather than 'Sent'.
    'imap_host' => 'imap.example.com',
    'imap_port' => 993,
    'imap_user' => 'you@example.com',
    'imap_pass' => '',
    'imap_sent' => 'INBOX.Sent',

    // Google OAuth, for pulling the calendar into the day view. The redirect URI
    // must match the one registered on the Google Cloud console exactly.
    'google_client_id'     => '',
    'google_client_secret' => '',
    'google_redirect_uri'  => 'https://example.com/crm/oauth_google.php',
    'google_calendar_id'   => 'primary',

    // Trello, for pushing a won deal onto the delivery board. Optional: leave
    // the key empty and the push is skipped.
    'trello_key'        => '',
    'trello_token'      => '',
    'trello_list_today' => '',
];
