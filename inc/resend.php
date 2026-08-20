<?php
/** Thin wrapper around the Resend email API -- the FROM address must belong to a domain verified with Resend or the send silently fails. */

function resend_send(string $to, string $subject, string $html): bool
{
    $cfg = config();
    $payload = json_encode([
        'from' => $cfg['resend_from'],
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ]);

    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['resend_api_key'],
            'Content-Length: ' . strlen($payload),
        ]),
        'content' => $payload,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);

    $resp = @file_get_contents('https://api.resend.com/emails', false, $ctx);
    if ($resp === false) {
        return false;
    }
    $data = json_decode($resp, true);
    return isset($data['id']);
}
