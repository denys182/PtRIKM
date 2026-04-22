<?php
declare(strict_types=1);

/**
 * ===== Editable configuration =====
 * Insert your real tokens/keys below.
 */
$verify_token = 'YOUR_FACEBOOK_VERIFY_TOKEN';
$access_token = 'YOUR_FACEBOOK_PAGE_ACCESS_TOKEN';
$openai_api_key = 'YOUR_OPENAI_API_KEY';
$assistant_id = 'asst_ubk5tYtfW2KyAbaRAXMl4EQR';
$openai_model = 'gpt-4o-mini';
$facebook_api_version = 'v19.0';
$thread_map_file = __DIR__ . '/fb-thread-map.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? null; // "hub.mode" arrives as "hub_mode" in PHP
    $token = $_GET['hub_verify_token'] ?? null; // "hub.verify_token" -> "hub_verify_token"
    $challenge = $_GET['hub_challenge'] ?? ''; // "hub.challenge" -> "hub_challenge"

    if ($mode === 'subscribe' && $token === $verify_token) {
        http_response_code(200);
        echo $challenge;
    } else {
        http_response_code(403);
        echo 'Invalid verify token';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$raw_payload = file_get_contents('php://input');
$webhook_data = json_decode($raw_payload, true);

if (!is_array($webhook_data)) {
    error_log('Invalid webhook JSON: ' . $raw_payload);
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

if (($webhook_data['object'] ?? '') !== 'page') {
    // Not a page event, but return 200 to avoid retries.
    http_response_code(200);
    echo 'IGNORED';
    exit;
}

foreach ($webhook_data['entry'] ?? [] as $entry) {
    foreach ($entry['messaging'] ?? [] as $event) {
        $sender_id = $event['sender']['id'] ?? null;
        $user_text = trim((string)($event['message']['text'] ?? ''));

        if (!$sender_id || $user_text === '') {
            continue;
        }

        try {
            $assistant_reply = generateAssistantReply(
                $sender_id,
                $user_text,
                $openai_api_key,
                $assistant_id,
                $openai_model,
                $thread_map_file
            );
        } catch (Throwable $e) {
            error_log('OpenAI assistant error: ' . $e->getMessage());
            $assistant_reply = 'Извини, сейчас не удалось обработать сообщение. Попробуй еще раз через пару секунд.';
        }

        try {
            sendFacebookMessage($sender_id, $assistant_reply, $access_token, $facebook_api_version);
        } catch (Throwable $e) {
            error_log('Facebook send error: ' . $e->getMessage());
        }
    }
}

http_response_code(200);
echo 'EVENT_RECEIVED';

function generateAssistantReply(
    string $sender_id,
    string $user_text,
    string $openai_api_key,
    string $assistant_id,
    string $openai_model,
    string $thread_map_file
): string {
    $thread_map = loadThreadMap($thread_map_file);
    $thread_id = $thread_map[$sender_id] ?? null;

    if (!$thread_id) {
        $thread = openaiRequest('POST', '/threads', $openai_api_key, [
            'metadata' => ['sender_id' => $sender_id],
        ]);
        $thread_id = $thread['id'] ?? null;
        if (!$thread_id) {
            throw new RuntimeException('OpenAI did not return thread id');
        }
        $thread_map[$sender_id] = $thread_id;
        saveThreadMap($thread_map_file, $thread_map);
    }

    openaiRequest(
        'POST',
        '/threads/' . rawurlencode($thread_id) . '/messages',
        $openai_api_key,
        [
            'role' => 'user',
            'content' => $user_text,
        ]
    );

    $run_payload = ['assistant_id' => $assistant_id];
    if ($openai_model !== '') {
        $run_payload['model'] = $openai_model;
    }

    $run = openaiRequest(
        'POST',
        '/threads/' . rawurlencode($thread_id) . '/runs',
        $openai_api_key,
        $run_payload
    );

    $run_id = $run['id'] ?? null;
    if (!$run_id) {
        throw new RuntimeException('OpenAI did not return run id');
    }

    $max_attempts = 40;
    $attempt = 0;
    do {
        usleep(750000); // 0.75 sec
        $attempt++;

        $run_status = openaiRequest(
            'GET',
            '/threads/' . rawurlencode($thread_id) . '/runs/' . rawurlencode($run_id),
            $openai_api_key
        );

        $status = $run_status['status'] ?? '';
        if ($status === 'completed') {
            break;
        }

        if (in_array($status, ['failed', 'cancelled', 'expired', 'incomplete', 'requires_action'], true)) {
            $error_text = $run_status['last_error']['message'] ?? ('Run status: ' . $status);
            throw new RuntimeException($error_text);
        }
    } while ($attempt < $max_attempts);

    if (($status ?? '') !== 'completed') {
        throw new RuntimeException('Run polling timeout');
    }

    $messages = openaiRequest(
        'GET',
        '/threads/' . rawurlencode($thread_id) . '/messages?order=desc&limit=20',
        $openai_api_key
    );

    foreach ($messages['data'] ?? [] as $message) {
        if (($message['role'] ?? '') !== 'assistant') {
            continue;
        }

        foreach ($message['content'] ?? [] as $content_item) {
            if (($content_item['type'] ?? '') === 'text') {
                $value = $content_item['text']['value'] ?? '';
                if (trim($value) !== '') {
                    return $value;
                }
            }
        }
    }

    throw new RuntimeException('No assistant text response found');
}

function openaiRequest(string $method, string $path, string $openai_api_key, ?array $payload = null): array
{
    $url = 'https://api.openai.com/v1' . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL for OpenAI');
    }

    $headers = [
        'Authorization: Bearer ' . $openai_api_key,
        'Content-Type: application/json',
        'OpenAI-Beta: assistants=v2',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
    ]);

    if ($payload !== null && $method !== 'GET') {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            curl_close($ch);
            throw new RuntimeException('Failed to encode OpenAI payload');
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
    }

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $status_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('OpenAI cURL error: ' . $curl_error);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('OpenAI invalid JSON response: ' . $response);
    }

    if ($status_code < 200 || $status_code >= 300) {
        $message = $decoded['error']['message'] ?? 'HTTP ' . $status_code;
        throw new RuntimeException('OpenAI API error: ' . $message);
    }

    return $decoded;
}

function sendFacebookMessage(string $recipient_id, string $text, string $access_token, string $facebook_api_version): void
{
    $url = sprintf(
        'https://graph.facebook.com/%s/me/messages?access_token=%s',
        rawurlencode($facebook_api_version),
        rawurlencode($access_token)
    );

    $payload = [
        'recipient' => ['id' => $recipient_id],
        'message' => ['text' => $text],
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL for Facebook');
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        curl_close($ch);
        throw new RuntimeException('Failed to encode Facebook payload');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $encoded,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $status_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Facebook cURL error: ' . $curl_error);
    }

    if ($status_code < 200 || $status_code >= 300) {
        throw new RuntimeException('Facebook API error (HTTP ' . $status_code . '): ' . $response);
    }
}

function loadThreadMap(string $thread_map_file): array
{
    if (!file_exists($thread_map_file)) {
        return [];
    }

    $raw = file_get_contents($thread_map_file);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveThreadMap(string $thread_map_file, array $thread_map): void
{
    $encoded = json_encode($thread_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new RuntimeException('Failed to encode thread map');
    }

    $tmp_file = $thread_map_file . '.tmp';
    if (file_put_contents($tmp_file, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write temporary thread map');
    }

    if (!rename($tmp_file, $thread_map_file)) {
        throw new RuntimeException('Failed to replace thread map file');
    }
}
