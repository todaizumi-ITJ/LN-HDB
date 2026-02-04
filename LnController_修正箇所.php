<?php
/**
 * LnController.php への追加コード
 *
 * 以下のメソッドを LnController クラスに追加してください。
 * registerProcess() 内で filterByHdb() を呼び出すように修正してください。
 */

// =====================================================
// 追加するメソッド（2つ）
// =====================================================

/**
 * HDBで和解済みIPNを除外
 *
 * @param array $ipnSerials IPNシリアル番号の配列
 * @return array 和解済みでないIPNの配列
 */
private function filterByHdb($ipnSerials) {
    if (empty($ipnSerials)) {
        return array();
    }

    // 方法1: API経由（HDBが別サーバーの場合）
    // $response = $this->callHdbFilterApi($ipnSerials);

    // 方法2: 直接DB参照（同一サーバーの場合）※推奨
    $response = $this->filterByHdbDirect($ipnSerials);

    if ($response && isset($response['filtered'])) {
        // 除外件数をログ出力（デバッグ用）
        if (isset($response['excluded_count']) && $response['excluded_count'] > 0) {
            error_log("[LN発行] HDBにより " . $response['excluded_count'] . " 件除外");
        }
        return $response['filtered'];
    }

    // 失敗時は元のリストを返す（フェイルセーフ）
    error_log("[LN発行] HDBフィルタ失敗、フィルタなしで続行");
    return $ipnSerials;
}

/**
 * HDB直接参照でフィルタ（同一サーバー用）
 */
private function filterByHdbDirect($ipnSerials) {
    // HDBのパス（環境に合わせて変更）
    $hdbPath = '/path/to/hdb.db';

    if (!file_exists($hdbPath)) {
        return array(
            'filtered' => $ipnSerials,
            'excluded_count' => 0,
            'warning' => 'HDB not found'
        );
    }

    $hdb = new SQLite3($hdbPath);

    $settled = array();
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
            continue;
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

    // 除外実行
    $filtered = array();
    $excluded = array();
    foreach ($ipnSerials as $serial) {
        if (isset($settled[$serial])) {
            $excluded[] = $serial;
        } else {
            $filtered[] = $serial;
        }
    }

    return array(
        'filtered' => $filtered,
        'excluded' => $excluded,
        'excluded_count' => count($excluded)
    );
}

/**
 * HDB API経由でフィルタ（別サーバー用）
 */
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }

    return json_decode($result, true);
}


// =====================================================
// registerProcess() の修正例
// =====================================================

/*
public function registerProcess() {
    // ... 既存のコード ...

    // IPN候補を取得
    $ipnCandidates = $this->getIpnCandidates($cwNum, $pprNum);
    $totalCount = count($ipnCandidates);

    // cre.db除外（従来通り）
    $ipnCandidates = $this->excludeSettledFromCre($ipnCandidates);
    $excludedByCre = $totalCount - count($ipnCandidates);

    // ★★★ 追加: HDBで和解済み除外 ★★★
    $beforeHdb = count($ipnCandidates);
    $ipnCandidates = $this->filterByHdb($ipnCandidates);
    $excludedByHdb = $beforeHdb - count($ipnCandidates);

    // LN登録実行
    $lnNum = $this->registerLn($ipnCandidates);

    // 結果をビューに渡す
    $this->view('ln/register_result', array(
        'ln_num' => $lnNum,
        'ipn_count' => count($ipnCandidates),
        'excluded_by_cre' => $excludedByCre,
        'excluded_by_hdb' => $excludedByHdb,  // ★追加
    ));
}
*/


// =====================================================
// ビュー側の修正例（views/ln/register_result.php）
// =====================================================

/*
<div class="alert alert-success">
    LN登録完了: <?php echo htmlspecialchars($ln_num); ?>
</div>

<table class="table">
    <tr>
        <th>登録IPN数</th>
        <td><?php echo number_format($ipn_count); ?> 件</td>
    </tr>
    <tr>
        <th>cre.db除外</th>
        <td><?php echo number_format($excluded_by_cre); ?> 件</td>
    </tr>
    <tr>
        <th>HDB除外（和解済み）</th>
        <td><?php echo number_format($excluded_by_hdb); ?> 件</td>
    </tr>
</table>
*/
