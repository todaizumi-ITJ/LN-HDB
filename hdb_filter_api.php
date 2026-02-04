<?php
/**
 * HDB和解済みIPNフィルタAPI
 *
 * LN発行時にIPNリストを受け取り、和解済みを除外して返す。
 * IPNDBには一切変更を加えない。
 *
 * 使用方法:
 *   POST /hdb_filter_api.php
 *   Content-Type: application/json
 *   Body: {"ipn_serials": ["ABC123", "DEF456", ...]}
 *
 *   Response:
 *   {
 *     "filtered": ["ABC123", ...],
 *     "excluded": ["DEF456", ...],
 *     "excluded_count": 1,
 *     "total_input": 2
 *   }
 */

// ===== 設定 =====
define('DB_HDB', '/path/to/hdb.db');  // 実際のパスに変更

// ===== CORS対応（必要に応じて） =====
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===== メイン処理 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['ipn_serials'])) {
        outputError('ipn_serials is required');
    }

    $ipnSerials = $input['ipn_serials'];

    if (!is_array($ipnSerials)) {
        outputError('ipn_serials must be an array');
    }

    $result = filterSettledIpns($ipnSerials);
    outputJson($result);
}

// GETでテスト用
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['test'])) {
    $testSerials = isset($_GET['serials']) ? explode(',', $_GET['serials']) : array();
    $result = filterSettledIpns($testSerials);
    outputJson($result);
}

/**
 * 和解済みIPNをフィルタ
 */
function filterSettledIpns($ipnSerials) {
    if (empty($ipnSerials)) {
        return array(
            'filtered' => array(),
            'excluded' => array(),
            'excluded_count' => 0,
            'total_input' => 0
        );
    }

    // 重複除去
    $ipnSerials = array_unique($ipnSerials);
    $totalInput = count($ipnSerials);

    // HDB接続
    if (!file_exists(DB_HDB)) {
        // HDBがない場合はフィルタなしで返す（フェイルセーフ）
        return array(
            'filtered' => array_values($ipnSerials),
            'excluded' => array(),
            'excluded_count' => 0,
            'total_input' => $totalInput,
            'warning' => 'HDB not found, no filtering applied'
        );
    }

    $hdb = new SQLite3(DB_HDB);

    // 和解済みIPNを取得
    $settledIpns = getSettledIpns($hdb, $ipnSerials);

    // フィルタ実行
    $filtered = array();
    $excluded = array();

    foreach ($ipnSerials as $serial) {
        if (isset($settledIpns[$serial])) {
            $excluded[] = $serial;
        } else {
            $filtered[] = $serial;
        }
    }

    return array(
        'filtered' => $filtered,
        'excluded' => $excluded,
        'excluded_count' => count($excluded),
        'total_input' => $totalInput
    );
}

/**
 * HDBから和解済みIPNを取得
 */
function getSettledIpns($hdb, $ipnSerials) {
    $settled = array();

    // バッチ処理（SQLite変数上限対応）
    foreach (array_chunk($ipnSerials, 900) as $batch) {
        $placeholders = implode(',', array_fill(0, count($batch), '?'));

        $sql = "
            SELECT DISTINCT hd.ipn_id
            FROM h_disclosure hd
            JOIN human h ON hd.hn_id = h.hn_id
            WHERE hd.ipn_id IN ($placeholders)
            AND h.settlement_status = '和解成立'
        ";

        $stmt = $hdb->prepare($sql);
        if (!$stmt) {
            continue;  // エラー時はスキップ
        }

        foreach ($batch as $i => $serial) {
            $stmt->bindValue($i + 1, $serial, SQLITE3_TEXT);
        }

        $result = $stmt->execute();
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $settled[$row['ipn_id']] = true;
            }
        }
    }

    return $settled;
}

/**
 * JSON出力
 */
function outputJson($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * エラー出力
 */
function outputError($message) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(array('error' => $message));
    exit;
}
