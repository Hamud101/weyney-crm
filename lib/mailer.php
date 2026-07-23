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

    public function send(string $to, string $subject, string $body, string $replyTo = ''): array {
        $host = cfg('smtp_host'); $port = (int)cfg('smtp_port', 465);
        $user = cfg('smtp_user'); $pass = cfg('smtp_pass');
        $from = cfg('smtp_from', $user);

        if (!$pass) return [false, 'SMTP password not configured', []];

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
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            if ($replyTo) $headers[] = 'Reply-To: <' . $replyTo . '>';

            // Dot-stuffing: a line that is just "." would end DATA early.
            $safe = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $body));
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

    private function encodeHeader(string $s): string {
        return preg_match('/[\x80-\xFF]/', $s)
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }
}

function send_mail(string $to, string $subject, string $body, string $replyTo = ''): array {
    return (new Smtp())->send($to, $subject, $body, $replyTo);
}
