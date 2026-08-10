<?php
require_once __DIR__ . '/config.php';

/**
 * Minimal SMTP over implicit TLS. No composer on this host, and PHP's mail()
 * can't do authenticated SMTP — this is the whole surface we need.
 *
 * Deliberately not a bulk sender: one message per call, sent synchronously,
 * so a failure surfaces to the person who clicked rather than into a log.
 */
class Smtp {
    private $fp;
    private array $log = [];

    private function rd(): string {
        $out = '';
        while ($line = fgets($this->fp, 1024)) {
            $out .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $this->log[] = '< ' . trim($out);
        return $out;
    }
    private function wr(string $cmd, bool $secret = false): void {
        $this->log[] = '> ' . ($secret ? '***' : $cmd);
        fwrite($this->fp, $cmd . "\r\n");
    }
    private function expect(string $code, string $what): void {
        $r = $this->rd();
        if (strpos($r, $code) !== 0) {
            throw new RuntimeException("$what: " . trim($r));
        }
    }

    /**
     * $attachments: [['path' => absolute path, 'name' => filename shown to the
     * recipient, 'type' => MIME type], …]. With none, the message stays the
     * plain single-part text/plain it always was.
     */
    public function send(string $to, string $subject, string $body, string $replyTo = '',
                         array $attachments = []): array {
        $host = cfg('smtp_host'); $port = (int)cfg('smtp_port', 465);
        $user = cfg('smtp_user'); $pass = cfg('smtp_pass');
        $from = cfg('smtp_from', $user);

        if (!$pass) return [false, 'SMTP password not configured', []];

        // Read the attachments before saying hello, so a missing file fails
        // here rather than half way through a DATA command.
        try {
            [$contentHeaders, $mimeBody] = $this->compose($body, $attachments);
        } catch (Throwable $e) {
            return [false, $e->getMessage(), []];
        }

        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $this->fp = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 20,
                                          STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->fp) return [false, "connect failed: $errstr", []];
        stream_set_timeout($this->fp, 20);

        try {
            $this->expect('220', 'banner');
            $this->wr('EHLO apps.weyney.com');           $this->expect('250', 'EHLO');
            $this->wr('AUTH LOGIN');                     $this->expect('334', 'AUTH');
            $this->wr(base64_encode($user));             $this->expect('334', 'username');
            $this->wr(base64_encode($pass), true);       $this->expect('235', 'password');
            $this->wr('MAIL FROM:<' . $from . '>');      $this->expect('250', 'MAIL FROM');
            $this->wr('RCPT TO:<' . $to . '>');          $this->expect('250', 'RCPT TO');
            $this->wr('DATA');                           $this->expect('354', 'DATA');

            $headers = [
                'From: Weyney Media <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@weyney.com>',
                'MIME-Version: 1.0',
            ];
            foreach ($contentHeaders as $h) $headers[] = $h;
            if ($replyTo) $headers[] = 'Reply-To: <' . $replyTo . '>';

            // One CRLF normalisation, then two versions of the same message:
            // the wire copy is dot-stuffed because a line of just "." would end
            // DATA early, and the filing copy below must not be.
            $crlf = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $mimeBody));
            $raw  = implode("\r\n", $headers) . "\r\n\r\n" . $crlf;

            fwrite($this->fp, implode("\r\n", $headers) . "\r\n\r\n"
                              . preg_replace('/^\./m', '..', $crlf) . "\r\n.\r\n");
            $this->expect('250', 'send');

            $this->wr('QUIT');
            fclose($this->fp);

            // The message is gone and cannot be unsent; from here nothing may
            // turn a delivered email into a reported failure.
            [$filed, $why] = mail_file_sent($raw);
            $this->log[] = $filed ? '# filed in Sent' : '# not filed: ' . $why;

            return [true, 'sent', $this->log, $filed];
        } catch (Throwable $e) {
            @fclose($this->fp);
            return [false, $e->getMessage(), $this->log, false];
        }
    }

    /**
     * Build the Content-* headers and the body they describe.
     *
     * No attachments: exactly what this class always sent, so nothing about
     * plain messages changes. With attachments: multipart/mixed, the text as
     * the first part and each file base64'd after it. Line breaks are left as
     * "\n" here — send() converts the whole payload to CRLF in one pass.
     */
    private function compose(string $body, array $attachments): array {
        if (!$attachments) {
            return [['Content-Type: text/plain; charset=UTF-8',
                     'Content-Transfer-Encoding: 8bit'], $body];
        }

        $b = '=_weyney_' . bin2hex(random_bytes(16));
        $out = "This is a message in MIME format.\n\n"
             . "--$b\n"
             . "Content-Type: text/plain; charset=UTF-8\n"
             . "Content-Transfer-Encoding: 8bit\n\n"
             . str_replace("\r\n", "\n", $body) . "\n";

        foreach ($attachments as $a) {
            $path = (string)($a['path'] ?? '');
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException('attachment missing: ' . basename($path));
            }
            $data = file_get_contents($path);
            if ($data === false) throw new RuntimeException('could not read ' . basename($path));

            // Quote-safe: the recipient's client shows this name, and a stray
            // quote or newline in it would break the header.
            $name = str_replace(['"', "\r", "\n"], '', $a['name'] ?? basename($path));
            $type = $a['type'] ?? 'application/octet-stream';

            $out .= "--$b\n"
                  . 'Content-Type: ' . $type . '; name="' . $name . "\"\n"
                  . "Content-Transfer-Encoding: base64\n"
                  . 'Content-Disposition: attachment; filename="' . $name . "\"\n\n"
                  . chunk_split(base64_encode($data), 76, "\n");
        }
        $out .= "--$b--\n";

        return [['Content-Type: multipart/mixed; boundary="' . $b . '"'], $out];
    }

    private function encodeHeader(string $s): string {
        return preg_match('/[\x80-\xFF]/', $s)
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }
}

/**
 * Put a copy of a message into the IMAP Sent folder.
 *
 * A Sent folder is written by whatever *composed* the message, never by the
 * mail server. Titan files what its own webmail types and nothing else, so for
 * a year everything this app relayed left no trace in the mailbox — the send
 * succeeded and the operator had no way to see it. Desktop clients solve this
 * by APPENDing after submission; so does this.
 *
 * Deliberately hand-rolled rather than using ext-imap. The extension is present
 * on the host today but was moved to PECL in PHP 8.4, and this site is still on
 * an end-of-life 8.0 that has to be bumped. Fifty lines of socket beats a
 * feature that disappears on upgrade day.
 *
 * Best-effort, always. It runs only after SMTP has already accepted the message,
 * and every failure returns instead of throwing: not filing a copy is a missing
 * receipt, not a missing email, and it must never be reported as one.
 */
function mail_file_sent(string $raw): array {
    $host = cfg('imap_host', 'imap.titan.email');
    $port = (int)cfg('imap_port', 993);
    $user = cfg('imap_user', cfg('smtp_user'));
    $pass = cfg('imap_pass', cfg('smtp_pass'));
    $box  = cfg('imap_sent', 'Sent');

    if (!$user || !$pass) return [false, 'IMAP not configured'];

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 15,
                                STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, "connect failed: $errstr"];
    stream_set_timeout($fp, 15);

    /* Read until the line carrying our tag. Untagged "*" status lines and the
       "+" continuation both arrive first and are not answers. */
    $reply = function (string $tag) use ($fp): string {
        $last = '';
        while (($line = fgets($fp, 8192)) !== false) {
            $last = trim($line);
            if ($last !== '' && $last[0] === '+') return $last;
            if (strncmp($last, $tag . ' ', strlen($tag) + 1) === 0) return $last;
        }
        return $last;
    };
    $quote = function (string $s): string {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    };

    try {
        if (strncmp((string)fgets($fp, 8192), '* OK', 4) !== 0) {
            throw new RuntimeException('no IMAP greeting');
        }

        fwrite($fp, 'a1 LOGIN ' . $quote($user) . ' ' . $quote($pass) . "\r\n");
        $r = $reply('a1');
        if (strncmp($r, 'a1 OK', 5) !== 0) throw new RuntimeException('login rejected');

        /* The literal length is a byte count of exactly what follows, which is
           why $raw had to be built CRLF-clean and un-dot-stuffed. */
        fwrite($fp, 'a2 APPEND ' . $quote($box) . ' (\\Seen) {' . strlen($raw) . "}\r\n");
        $r = $reply('a2');
        if ($r === '' || $r[0] !== '+') throw new RuntimeException('APPEND refused: ' . $r);

        fwrite($fp, $raw . "\r\n");
        $r = $reply('a2');
        if (strncmp($r, 'a2 OK', 5) !== 0) throw new RuntimeException('APPEND failed: ' . $r);

        fwrite($fp, "a3 LOGOUT\r\n");
        fclose($fp);
        return [true, 'filed'];
    } catch (Throwable $e) {
        @fclose($fp);
        return [false, $e->getMessage()];
    }
}

/**
 * Returns [$ok, $info, $log, $filed]. $filed says whether a copy reached the
 * Sent folder and is only meaningful when $ok — callers that don't care can
 * destructure the first two and ignore the rest.
 */
function send_mail(string $to, string $subject, string $body, string $replyTo = '',
                   array $attachments = []): array {
    return (new Smtp())->send($to, $subject, $body, $replyTo, $attachments);
}
