# LN発行 HDBフィルタ方式 設計書

## コンセプト

**IPNDBは触らない。LN発行後にHDBで和解済みを除外する。**

```
┌─────────────────┐
│  LN発行画面     │
│  (IPN検索)      │  ← 従来通り、IPNDBを検索
└────────┬────────┘
         │ IPN候補リスト
         ▼
┌─────────────────┐
│  HDB            │
│  和解フィルタ   │  ← 和解済みIPNを除外
└────────┬────────┘
         │ フィルタ済みリスト
         ▼
┌─────────────────┐
│  LN登録確定     │
└─────────────────┘
```

---

## 変更点

| 対象 | 変更内容 |
|------|----------|
| IPNDB | **変更なし** |
| cre.db | **変更なし** |
| vpngate_logs.db | **変更なし** |
| HDB | フィルタAPI追加（1関数のみ） |
| LN発行ロジック | フィルタ呼び出し追加（数行） |

---

## HDB側：フィルタAPI

### 関数仕様

```php
/**
 * IPNリストから和解済みを除外して返す
 *
 * @param array $ipnSerials IPNシリアル番号の配列
 * @return array 和解済みでないIPNのみの配列
 */
function filterSettledIpns($ipnSerials) {
    // 入力: ['IPN001', 'IPN002', 'IPN003', ...]
    // 出力: ['IPN001', 'IPN003', ...]  ← IPN002が和解済みなら除外
}
```

### 実装（hdb_filter_api.php）

```php
<?php
/**
 * HDB和解済みIPNフィルタAPI
 *
 * 使用方法:
 *   POST /hdb_filter_api.php
 *   Content-Type: application/json
 *   Body: {"ipn_serials": ["IPN001", "IPN002", ...]}
 *
 *   Response: {"filtered": ["IPN001", "IPN003", ...], "excluded_count": 1}
 */

define('DB_HDB', '/path/to/hdb.db');

// リクエスト処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $ipnSerials = isset($input['ipn_serials']) ? $input['ipn_serials'] : array();

    $result = filterSettledIpns($ipnSerials);

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

function filterSettledIpns($ipnSerials) {
    if (empty($ipnSerials)) {
        return array('filtered' => array(), 'excluded_count' => 0);
    }

    $hdb = new SQLite3(DB_HDB);

    // 和解済みIPNを取得
    $settledIpns = getSettledIpns($hdb, $ipnSerials);

    // 除外
    $filtered = array();
    foreach ($ipnSerials as $serial) {
        if (!isset($settledIpns[$serial])) {
            $filtered[] = $serial;
        }
    }

    return array(
        'filtered' => $filtered,
        'excluded_count' => count($ipnSerials) - count($filtered)
    );
}

function getSettledIpns($hdb, $ipnSerials) {
    $settled = array();

    // バッチ処理（900件ずつ）
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
        foreach ($batch as $i => $serial) {
            $stmt->bindValue($i + 1, $serial, SQLITE3_TEXT);
        }

        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $settled[$row['ipn_id']] = true;
        }
    }

    return $settled;
}
```

---

## IPNDB側：LN発行ロジックの修正

### 修正箇所（LnController.php の registerProcess）

```php
// 【変更前】従来のLN登録処理
public function registerProcess() {
    // ... IPN候補を取得 ...
    $ipnCandidates = $this->getIpnCandidates($cwNum, $pprNum);

    // cre.db除外（従来通り）
    $ipnCandidates = $this->excludeSettledFromCre($ipnCandidates);

    // LN登録実行
    $this->registerLn($ipnCandidates);
}

// 【変更後】HDBフィルタを追加
public function registerProcess() {
    // ... IPN候補を取得 ...
    $ipnCandidates = $this->getIpnCandidates($cwNum, $pprNum);

    // cre.db除外（従来通り）
    $ipnCandidates = $this->excludeSettledFromCre($ipnCandidates);

    // ★追加: HDBで和解済み除外
    $ipnCandidates = $this->filterByHdb($ipnCandidates);

    // LN登録実行
    $this->registerLn($ipnCandidates);
}

/**
 * HDBで和解済みIPNを除外
 */
private function filterByHdb($ipnSerials) {
    if (empty($ipnSerials)) {
        return array();
    }

    // HDB APIを呼び出し
    $response = $this->callHdbFilterApi($ipnSerials);

    if ($response && isset($response['filtered'])) {
        return $response['filtered'];
    }

    // API失敗時は元のリストを返す（フェイルセーフ）
    return $ipnSerials;
}

private function callHdbFilterApi($ipnSerials) {
    $url = 'https://hdb.example.com/hdb_filter_api.php';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
        'ipn_serials' => $ipnSerials
    )));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    curl_close($ch);

    return json_decode($result, true);
}
```

---

## 代替案：直接DB参照（API不要）

HDBが同一サーバーにある場合、APIを使わずに直接参照も可能：

```php
private function filterByHdb($ipnSerials) {
    if (empty($ipnSerials)) {
        return array();
    }

    // HDBに直接接続
    $hdb = new SQLite3('/path/to/hdb.db');

    $settled = array();
    foreach (array_chunk($ipnSerials, 900) as $batch) {
        $placeholders = implode(',', array_fill(0, count($batch), '?'));
        $stmt = $hdb->prepare("
            SELECT DISTINCT hd.ipn_id
            FROM h_disclosure hd
            JOIN human h ON hd.hn_id = h.hn_id
            WHERE hd.ipn_id IN ($placeholders)
            AND h.settlement_status = '和解成立'
        ");

        foreach ($batch as $i => $serial) {
            $stmt->bindValue($i + 1, $serial, SQLITE3_TEXT);
        }

        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $settled[$row['ipn_id']] = true;
        }
    }

    // 除外
    return array_filter($ipnSerials, function($serial) use ($settled) {
        return !isset($settled[$serial]);
    });
}
```

---

## フロー図

```
┌────────────────────────────────────────────────────────────────┐
│                      LN発行フロー                              │
├────────────────────────────────────────────────────────────────┤
│                                                                │
│  1. 品番・PPR選択                                              │
│         │                                                      │
│         ▼                                                      │
│  2. IPNDB検索 ──────────────────────┐                         │
│     (vpngate_logs.db)               │                         │
│         │                           │  ← IPNDBは変更なし      │
│         ▼                           │                         │
│  3. cre.db除外（従来通り）──────────┘                         │
│         │                                                      │
│         ▼                                                      │
│  4. ★HDBフィルタ（新規追加）                                   │
│     - IPNリストをHDBに送信                                     │
│     - 和解成立済みを除外                                       │
│     - フィルタ済みリストを取得                                 │
│         │                                                      │
│         ▼                                                      │
│  5. LN登録確定                                                 │
│                                                                │
└────────────────────────────────────────────────────────────────┘
```

---

## メリット

| 項目 | 説明 |
|------|------|
| **IPNDB変更なし** | vpngate_logs.db、cre.db、ln.db全て触らない |
| **実装が軽量** | HDBに1関数追加、IPNDBに数行追加のみ |
| **フェイルセーフ** | HDB接続失敗時は従来通り動作 |
| **将来拡張可能** | HDB側でフィルタ条件を自由に変更できる |

---

## 実装手順

1. **HDB側**: `hdb_filter_api.php` を配置（または直接参照関数を追加）
2. **IPNDB側**: `LnController.php` に `filterByHdb()` を追加
3. **テスト**: dry-runで除外件数を確認
4. **本番適用**

---

## 補足：除外件数の表示

LN発行画面で「HDBにより○件除外」を表示すると分かりやすい：

```php
// LN登録プレビュー画面
$beforeCount = count($ipnCandidates);
$ipnCandidates = $this->filterByHdb($ipnCandidates);
$excludedByHdb = $beforeCount - count($ipnCandidates);

// ビューに渡す
$this->view('ln/register_preview', array(
    'candidates' => $ipnCandidates,
    'excluded_by_cre' => $excludedByCre,
    'excluded_by_hdb' => $excludedByHdb,  // ★追加
));
```

---

**作成日**: 2026-02-04
