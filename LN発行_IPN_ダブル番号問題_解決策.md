# LN発行時のIPN・ダブル番号問題 解決策

## 1. 問題の整理

### 1.1 現状の課題

| 課題 | 詳細 |
|------|------|
| **和解済み案件の排除不備** | LN発行時に和解済み案件が混在するリスクがある |
| **IPNとダブル番号の紐づき不足** | `ada.db`にIP情報はあるが、和解済み案件との統合にリスクあり |
| **処理ループ問題** | IPNを検索キーに使い続けることで、同一案件が何度も処理に入る |
| **データ分断** | IPNDB・HDB・cre.db間でデータが分断されている |

### 1.2 現行システムのデータフロー

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   vpngate_logs  │     │     ln.db       │     │     cre.db      │
│   (IPNログ)     │────▶│  (LN-IPN紐付け) │────▶│  (和解済みIPN)  │
│   24M件         │     │   275K件        │     │   257K件        │
└─────────────────┘     └─────────────────┘     └─────────────────┘
         │                                               ▲
         │                                               │
         ▼                                               │
┌─────────────────┐                            ┌─────────────────┐
│     ada.db      │◀──────────────────────────▶│      HDB        │
│   (債務者管理)  │     紐づき不十分            │  (発信者管理)   │
└─────────────────┘                            └─────────────────┘
```

---

## 2. 解決策

### 2.1 方針A: HDBの和解ステータスをcre.dbに自動同期（推奨）

**概要**: HDBで和解成立したら、該当IPNを自動的に`cre.db`の`registered_serials`に追加。

**メリット**:
- 既存のLN発行ロジックを変更不要
- シンプルな実装
- 負荷が低い

**実装方法**:

```sql
-- HDB側: 和解成立時のトリガー（h_settlementテーブル）
CREATE TRIGGER sync_settlement_to_cre
AFTER UPDATE ON h_settlement
WHEN NEW.settlement_status = '和解成立'
BEGIN
    -- 該当発信者に紐付くIPNをcre.dbに登録
    INSERT OR IGNORE INTO cre_sync_queue (ipn_serial, hn_id, synced_at)
    SELECT hd.ipn_id, NEW.hn_id, datetime('now')
    FROM h_disclosure hd
    WHERE hd.hn_id = NEW.hn_id;
END;
```

**同期スクリプト（PHP）**:

```php
<?php
// sync_settlement_to_cre.php
// HDBの和解済み案件をcre.dbに同期

function syncSettlementToCre() {
    $hdb = new SQLite3('/path/to/hdb.db');
    $cre = new SQLite3('/path/to/cre.db');

    // 和解成立した発信者に紐付くIPN取得
    $sql = "
        SELECT hd.ipn_id as serial
        FROM human h
        JOIN h_disclosure hd ON h.hn_id = hd.hn_id
        JOIN h_settlement hs ON h.hn_id = hs.hn_id
        WHERE hs.settlement_status = '和解成立'
        AND hd.ipn_id NOT IN (
            SELECT serial FROM registered_serials
            UNION
            SELECT serial FROM registered_serials_static
        )
    ";

    $result = $hdb->query($sql);
    $batch = array();

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $batch[] = $row['serial'];

        if (count($batch) >= 900) {
            insertBatch($cre, $batch);
            $batch = array();
        }
    }

    if (!empty($batch)) {
        insertBatch($cre, $batch);
    }
}

function insertBatch($db, $serials) {
    $placeholders = implode(',', array_fill(0, count($serials), '(?)'));
    $stmt = $db->prepare("INSERT OR IGNORE INTO registered_serials (serial) VALUES $placeholders");

    foreach ($serials as $i => $serial) {
        $stmt->bindValue($i + 1, $serial, SQLITE3_TEXT);
    }
    $stmt->execute();
}
```

---

### 2.2 方針B: LN発行ロジックにHDB参照を追加

**概要**: LN発行時にcre.dbだけでなくHDBの和解ステータスも直接参照。

**実装方法（LnController.phpの修正）**:

```php
// 現行: cre.dbのみ参照
$sql = "
    SELECT serial FROM ln_ipn_mapping WHERE serial = ?
    UNION
    SELECT serial FROM registered_serials_all WHERE serial = ?
";

// 改善案: HDBも参照
function getExcludedSerials($serial) {
    $excluded = array();

    // 1. 既存LN登録済み
    $lnDb = new SQLite3(DB_LN);
    $stmt = $lnDb->prepare("SELECT serial FROM ln_ipn_mapping WHERE serial = ?");
    $stmt->bindValue(1, $serial, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result->fetchArray()) {
        $excluded['ln_registered'] = true;
    }

    // 2. cre.db和解済み
    $creDb = new SQLite3(DB_CRE);
    $stmt = $creDb->prepare("SELECT serial FROM registered_serials_all WHERE serial = ?");
    $stmt->bindValue(1, $serial, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result->fetchArray()) {
        $excluded['cre_settled'] = true;
    }

    // 3. HDB和解済み（追加）
    $hdb = new SQLite3(DB_HDB);
    $stmt = $hdb->prepare("
        SELECT hd.ipn_id
        FROM h_disclosure hd
        JOIN human h ON hd.hn_id = h.hn_id
        WHERE hd.ipn_id = ?
        AND h.settlement_status = '和解成立'
    ");
    $stmt->bindValue(1, $serial, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result->fetchArray()) {
        $excluded['hdb_settled'] = true;
    }

    return $excluded;
}
```

---

### 2.3 方針C: NN（新規番号）自動発行による軽量化

**概要**: 会社請求DBでNN（新規番号）を発行し、IPNを直接触らずに処理。

```
┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│  品番選択    │───▶│  NN自動発行  │───▶│  LN発行判定  │
│  PPR選択     │    │  (会社請求DB)│    │  (和解除外)  │
└──────────────┘    └──────────────┘    └──────────────┘
                            │
                            ▼
                    ┌──────────────┐
                    │  結果戻し    │
                    │  (IPN更新)   │
                    └──────────────┘
```

**NN管理テーブル（新規）**:

```sql
CREATE TABLE nn_master (
    nn_id INTEGER PRIMARY KEY AUTOINCREMENT,
    nn_code TEXT UNIQUE NOT NULL,           -- NN00001形式
    cw_num INTEGER NOT NULL,
    ppr_num INTEGER NOT NULL,
    status TEXT DEFAULT 'pending',          -- pending/processed/cancelled
    ln_num INTEGER,                         -- LN発行後に設定
    ipn_count INTEGER DEFAULT 0,
    settled_count INTEGER DEFAULT 0,        -- 和解済み件数
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE nn_ipn_mapping (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nn_id INTEGER NOT NULL,
    ipn_serial TEXT NOT NULL,
    is_settled INTEGER DEFAULT 0,           -- 和解済みフラグ
    checked_at DATETIME,
    FOREIGN KEY (nn_id) REFERENCES nn_master(nn_id),
    UNIQUE(nn_id, ipn_serial)
);
```

---

## 3. 推奨実装順序

| 優先度 | 方針 | 実装難易度 | 効果 |
|--------|------|-----------|------|
| 1 | **方針A** | ★☆☆☆☆ | 既存ロジックの変更なしで和解済み同期 |
| 2 | 方針B | ★★☆☆☆ | LN発行時の二重チェック強化 |
| 3 | 方針C | ★★★☆☆ | 根本的な処理構造の改善 |

---

## 4. 具体的な実装ステップ（方針A）

### Step 1: 同期スクリプトの作成

```bash
# /var/www/lo/y/sync_hdb_cre.php
```

### Step 2: cronジョブの設定

```bash
# 1時間ごとに同期
0 * * * * php /var/www/lo/y/sync_hdb_cre.php >> /var/log/sync_hdb_cre.log 2>&1
```

### Step 3: 初回同期の実行

```bash
php sync_hdb_cre.php --initial
```

### Step 4: 動作確認

```bash
# cre.dbの件数確認
sqlite3 cre.db "SELECT COUNT(*) FROM registered_serials_all"

# 同期ログ確認
tail -f /var/log/sync_hdb_cre.log
```

---

## 5. データ整合性チェッククエリ

### 5.1 和解済みなのにLN登録されている案件の検出

```sql
-- ada.db/HDBに和解情報があるがcre.dbにない
SELECT DISTINCT d.ipn_serial
FROM debtor_ipn_mapping d
JOIN debtor db ON d.debtor_id = db.id
WHERE db.progress LIKE '%和解%'
AND d.ipn_serial NOT IN (
    SELECT serial FROM cre.registered_serials_all
);
```

### 5.2 重複処理検出

```sql
-- 同一IPNが複数LNに登録されている
SELECT serial, COUNT(*) as ln_count
FROM ln_ipn_mapping
GROUP BY serial
HAVING COUNT(*) > 1;
```

---

## 6. 移行計画

| フェーズ | 期間 | 内容 |
|---------|------|------|
| 1 | 1週間 | 方針Aの同期スクリプト作成・テスト |
| 2 | 1週間 | 本番環境での初回同期・動作確認 |
| 3 | 継続 | 定期同期の運用開始 |
| 4 | 必要に応じて | 方針B/Cの追加実装 |

---

## 7. 補足: IPNとHNの紐付け強化

現在の`h_disclosure`テーブルを活用して、IPNとHN（発信者番号）の紐付けを確実にする。

```sql
-- h_disclosureテーブルの活用
-- ipn_id: IPN番号
-- hn_id: 発信者番号

-- 紐付けが不十分な場合のチェック
SELECT COUNT(*) as orphan_disclosures
FROM h_disclosure
WHERE hn_id IS NULL;

-- 未紐付けIPNへの自動紐付け（住所マッチング）
UPDATE h_disclosure
SET hn_id = (
    SELECT h.hn_id
    FROM human h
    WHERE h.address = h_disclosure.disclosed_address
    AND h.contract_name = h_disclosure.disclosed_name
    LIMIT 1
)
WHERE hn_id IS NULL;
```

---

**作成日**: 2026-02-04
**作成者**: Claude
