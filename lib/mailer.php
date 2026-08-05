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

            // Dot-stuffing: a line that is just "." would end DATA early.
            $safe = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $mimeBody));
            $safe = str_replace("\n", "\r\n", $safe);

            fwrite($this->fp, implode("\r\n", $headers) . "\r\n\r\n" . $safe . "\r\n.\r\n");
            $this->expect('250', 'send');

            $this->wr('QUIT');
            fclose($this->fp);
            return [true, 'sent', $this->log];
        } catch (Throwable $e) {
            @fclose($this->fp);
            return [false, $e->getMessage(), $this->log];
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

function send_mail(string $to, string $subject, string $body, string $replyTo = '',
                   array $attachments = []): array {
    return (new Smtp())->send($to, $subject, $body, $replyTo, $attachments);
}
