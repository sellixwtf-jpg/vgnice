<?php
header('Content-Type: application/json');

define('DEBUG_LOG', __DIR__ . '/debug.log');

function debug_log($msg) {
    file_put_contents(DEBUG_LOG, date('Y-m-d H:i:s') . " " . $msg . "\n", FILE_APPEND);
}

define('SESSIONS_FILE', __DIR__ . '/sessions.json');
define('TASKS_DATA_FILE', __DIR__ . '/tasks_data.json');
define('GATEWAY_API_KEY', 'fiverdbull');
define('VANGUARD_UA', 'vanguard/1.19.0-4+20260821.201419');

function loadSessions(): array {
    return file_exists(SESSIONS_FILE) ? json_decode(file_get_contents(SESSIONS_FILE), true) ?: [] : [];
}

function saveSessions(array $sessions): void {
    file_put_contents(SESSIONS_FILE, json_encode($sessions, JSON_PRETTY_PRINT));
}

function generateSessionId(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

function loadTasks(): array {
    return file_exists(TASKS_DATA_FILE) ? json_decode(file_get_contents(TASKS_DATA_FILE), true) ?: [] : [];
}

function saveTasks(array $tasks): void {
    file_put_contents(TASKS_DATA_FILE, json_encode($tasks, JSON_PRETTY_PRINT));
}

function generateTaskId(): string {
    return 'task_' . uniqid() . '_' . bin2hex(random_bytes(4));
}

function createTask(string $session_id, string $type = 'npt'): array {
    $task_id = generateTaskId();
    $task = [
        'task_id' => $task_id,
        'session_id' => $session_id,
        'type' => $type,
        'status' => 'pending',
        'created_at' => time(),
        'data' => [
            'npt' => [
                'cpu' => 13,
                'device' => ['logical_cpu_count' => 8, 'platform' => 'windows'],
                'qpc_source' => 'hv_state7',
                'probes' => 32
            ]
        ]
    ];
    $tasks = loadTasks();
    $tasks[$task_id] = $task;
    saveTasks($tasks);
    return $task;
}

function getPendingTasks(string $session_id): array {
    $tasks = loadTasks();
    $pending = [];
    foreach ($tasks as $id => $task) {
        if ($task['session_id'] === $session_id && $task['status'] === 'pending') {
            $pending[] = $task;
        }
    }
    return $pending;
}

function completeTask(string $task_id, array $result): bool {
    $tasks = loadTasks();
    if (!isset($tasks[$task_id])) return false;
    $tasks[$task_id]['status'] = 'completed';
    $tasks[$task_id]['completed_at'] = time();
    $tasks[$task_id]['result'] = $result;
    saveTasks($tasks);
    return true;
}

function sendToGateway(string $payload, string $region, string $action = 'auth', string $puuid = ''): string {
    $host = $region . '.vg.ac.pvp.net';
    // EMU ACCESS/HB と同じ: HTTPS 443 + /vanguard/v1/gateway（8443 は CDN 用）
    $url = 'https://' . $host . '/vanguard/v1/gateway';

    $action_map = [
        'auth' => '3',
        'access' => '4',
        'heartbeat' => '7',
        'task_result' => '9',
    ];
    $vg_type = $action_map[$action] ?? '3';
    $ua = VANGUARD_UA;

    debug_log("sendToGateway: url=" . $url . " action=" . $action . " xvg1=" . $vg_type . " ua=" . $ua . " payload_len=" . strlen($payload) . " puuid=" . $puuid);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);

    $headers = [
        'Host: ' . $host,
        'Content-Type: application/x-protobuf',
        'Accept: application/x-protobuf',
        'User-Agent: ' . $ua,
        'X-VG-1: ' . $vg_type,
        'X-VG-3: 1',
        'X-VG-4: com.riotgames.valorant',
    ];
    if ($puuid !== '') {
        $headers[] = 'X-VG-2: ' . $puuid;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    debug_log("sendToGateway: httpCode=" . $httpCode . " response_len=" . strlen((string)$response));
    if (!empty($curlError)) {
        debug_log("sendToGateway: curl_error=" . $curlError);
    }
    if ($httpCode !== 200 && $httpCode !== 201 && $response !== false && $response !== '') {
        debug_log("sendToGateway: riot_body=" . substr($response, 0, 300));
    }

    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new RuntimeException("Gateway returned HTTP $httpCode: " . substr((string)$response, 0, 200));
    }
    return $response;
}

function handleGatewayAction(array $input): array {
    debug_log("handleGatewayAction called");

    $d = $input['d'] ?? '';
    $puuid = $input['puuid'] ?? '';
    $region = $input['region'] ?? 'ap';
    $action_type = $input['type'] ?? 'auth';

    debug_log("handleGatewayAction: region=" . $region . " type=" . $action_type . " d_len=" . strlen($d) . " puuid=" . $puuid);

    if (empty($d)) {
        return ['success' => false, 'error' => 'missing d field'];
    }

    $decoded = base64_decode($d, true);
    if ($decoded === false || $decoded === '') {
        debug_log("handleGatewayAction: base64 decode failed");
        return ['success' => false, 'error' => 'invalid base64 data'];
    }

    debug_log("handleGatewayAction: decoded_len=" . strlen($decoded));

    try {
        $action = 'auth';
        if ($action_type === '4' || $action_type === 'access') {
            $action = 'access';
        } elseif ($action_type === '7' || $action_type === 'heartbeat') {
            $action = 'heartbeat';
        } elseif ($action_type === '5' || $action_type === '9' || $action_type === 'task_result') {
            $action = 'task_result';
        }

        debug_log("handleGatewayAction: sending to gateway with action=" . $action);

        $gatewayResponse = sendToGateway($decoded, $region, $action, $puuid);

        if ($gatewayResponse === '' || $gatewayResponse === false) {
            return ['success' => false, 'error' => 'empty gateway response'];
        }

        debug_log("handleGatewayAction: gateway response len=" . strlen($gatewayResponse));

        return [
            'success' => true,
            'data' => base64_encode($gatewayResponse)
        ];
    } catch (Exception $e) {
        debug_log("handleGatewayAction: exception: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

$raw_input = file_get_contents("php://input");
debug_log("=== REQUEST START ===");
debug_log("raw_input: " . substr($raw_input, 0, 200) . "...");

$input = json_decode($raw_input, true);
if (!is_array($input)) {
    debug_log("invalid JSON input");
    http_response_code(400);
    die(json_encode(["success" => false, "message" => "invalid input"]));
}

$t = $input["t"] ?? '';
if ($t !== '' && $t !== GATEWAY_API_KEY) {
    debug_log("bad t");
    http_response_code(400);
    die(json_encode(["success" => false, "message" => "unauthorized"]));
}

$action = $input["action"] ?? "auth";
$gameToken = $input["gametoken"] ?? $input["token"] ?? null;
$sid = $input["sid"] ?? null;
$session_id = $input["session_id"] ?? null;
$region = strtolower(trim($input["region"] ?? 'ap'));

debug_log("action: " . $action);

if ($action === "gateway") {
    debug_log("Processing gateway relay action");
    $result = handleGatewayAction($input);
    if ($result['success']) {
        die(json_encode(["success" => true, "data" => $result['data']]));
    }
    http_response_code(400);
    die(json_encode(["success" => false, "message" => $result['error'] ?? "gateway failed"]));
}

if ($action === "hb_blob") {
    $session_id = $input['session_id'] ?? null;
    debug_log("HB_BLOB: session_id=" . $session_id);
    if (!$session_id) {
        die(json_encode(["success" => false, "message" => "missing session_id"]));
    }
    $sessions = loadSessions();
    if (!isset($sessions[$session_id])) {
        $sessions[$session_id] = [
            'session_id' => $session_id,
            'sid' => $session_id,
            'token' => '',
            'region' => $region,
            'created_at' => time(),
            'updated_at' => time(),
            'tasks_cleared' => false,
            'auto_created' => true
        ];
        saveSessions($sessions);
    }
    $task = createTask($session_id, 'npt');
    $hb_blob = base64_encode(random_bytes(64));
    die(json_encode([
        "success" => true,
        "data" => $hb_blob,
        "task_ids" => [$task['task_id']],
        "cdn_paths" => ["/content/path"],
        "ledger_len" => 3
    ]));
}

if ($action === "task_result") {
    $task_id = $input['task_id'] ?? null;
    $data = $input['data'] ?? null;
    if (!$task_id || !$data) {
        die(json_encode(["success" => false, "message" => "missing task_id or data"]));
    }
    $result_data = base64_decode($data);
    $completed = completeTask($task_id, [
        'status' => 'success',
        'data' => $result_data,
        'decoded' => bin2hex($result_data)
    ]);
    die(json_encode([
        "success" => $completed,
        "message" => $completed ? "task completed" : "task not found"
    ]));
}

if ($action === "task_status") {
    $task_id = $input['task_id'] ?? null;
    $session_id = $input['session_id'] ?? null;
    if (!$task_id && !$session_id) {
        die(json_encode(["success" => false, "message" => "missing task_id or session_id"]));
    }
    $tasks = loadTasks();
    $result = [];
    if ($task_id) {
        if (isset($tasks[$task_id])) $result = $tasks[$task_id];
    } else if ($session_id) {
        foreach ($tasks as $id => $task) {
            if ($task['session_id'] === $session_id) $result[$id] = $task;
        }
    }
    die(json_encode(["success" => true, "tasks" => $result]));
}

if ($action === "task_clear") {
    $session_id = $input['session_id'] ?? null;
    if (!$session_id) {
        die(json_encode(["success" => false, "message" => "missing session_id"]));
    }
    $tasks = loadTasks();
    $cleared = 0;
    foreach ($tasks as $id => $task) {
        if ($task['session_id'] === $session_id && $task['status'] === 'pending') {
            $tasks[$id]['status'] = 'cleared';
            $tasks[$id]['cleared_at'] = time();
            $cleared++;
        }
    }
    saveTasks($tasks);
    die(json_encode(["success" => true, "cleared" => $cleared]));
}

if ($action === "create_session") {
    $session_id = $input['session_id'] ?? null;
    $sid = $input['sid'] ?? '';
    $region = $input['region'] ?? 'ap';
    if (!$session_id) {
        die(json_encode(["success" => false, "message" => "missing session_id"]));
    }
    $sessions = loadSessions();
    if (!isset($sessions[$session_id])) {
        $sessions[$session_id] = [
            'session_id' => $session_id,
            'sid' => $sid,
            'token' => '',
            'region' => $region,
            'created_at' => time(),
            'updated_at' => time(),
            'tasks_cleared' => false
        ];
        saveSessions($sessions);
    }
    die(json_encode(["success" => true, "session_id" => $session_id]));
}

if ($action === "auth") {
    if (!$gameToken) {
        http_response_code(400);
        die(json_encode(["success" => false, "message" => "missing gametoken"]));
    }
    $newId = generateSessionId();
    $sessions = loadSessions();
    $sessions[$newId] = [
        'session_id' => $newId,
        'sid' => $sid ?? '',
        'token' => $gameToken,
        'region' => $region,
        'created_at' => time(),
        'updated_at' => time(),
        'tasks_cleared' => false
    ];
    saveSessions($sessions);
    die(json_encode(["success" => true, "session_id" => $newId]));
}

if ($action === "submit") {
    $token = $input["token"] ?? null;
    $sid = $input["sid"] ?? null;
    $region = $input["region"] ?? 'ap';
    if (!$token || !$sid) {
        http_response_code(400);
        die(json_encode(["success" => false, "message" => "missing token or sid"]));
    }
    $sessions = loadSessions();
    $existing = null;
    foreach ($sessions as $id => $sess) {
        if ($sess['sid'] === $sid) {
            $existing = $id;
            break;
        }
    }
    if ($existing) {
        $sessions[$existing]['token'] = $token;
        $sessions[$existing]['region'] = $region;
        $sessions[$existing]['updated_at'] = time();
        $sessions[$existing]['ticket'] = base64_encode(random_bytes(64));
        saveSessions($sessions);
        die(json_encode(["success" => true, "session_id" => $existing]));
    }
    $newId = generateSessionId();
    $sessions[$newId] = [
        'session_id' => $newId,
        'sid' => $sid,
        'token' => $token,
        'region' => $region,
        'ticket' => base64_encode(random_bytes(64)),
        'created_at' => time(),
        'updated_at' => time(),
        'tasks_cleared' => false
    ];
    saveSessions($sessions);
    die(json_encode(["success" => true, "session_id" => $newId]));
}

if ($action === "poll") {
    if (!$session_id) {
        http_response_code(400);
        die(json_encode(["success" => false, "message" => "missing session_id"]));
    }
    $sessions = loadSessions();
    if (!isset($sessions[$session_id])) {
        http_response_code(404);
        die(json_encode(["success" => false, "message" => "session not found"]));
    }
    $sess = $sessions[$session_id];
    if (empty($sess['ticket'])) {
        die(json_encode(["status" => "pending"]));
    }
    $ticket = $sess['ticket'];
    $sess['ticket'] = null;
    $sessions[$session_id] = $sess;
    saveSessions($sessions);
    die(json_encode(["status" => "ready", "ticket" => $ticket]));
}

debug_log("unknown action: " . $action);
http_response_code(400);
die(json_encode(["success" => false, "message" => "unknown action: " . $action]));
