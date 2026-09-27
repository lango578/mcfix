<?php

declare(strict_types=1);

namespace MCFix;

use PDO;
use PDOException;

/**
 * SQLite 数据访问层。所有表结构都通过 ensureSchema() 幂等创建，
 * 因此升级时只要覆盖代码再打开一次页面即可完成迁移。
 */
final class Db
{
    private static $pdo = null;

    private static $driver = 'sqlite';

    private static $ready = false;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = (string) Config::get('db.driver', 'sqlite');
        self::$driver = $driver === 'mysql' ? 'mysql' : 'sqlite';

        if (self::$driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) Config::get('db.host', '127.0.0.1'),
                (int) Config::get('db.port', 3306),
                (string) Config::get('db.database', 'mcfix')
            );
            self::$pdo = new PDO($dsn, (string) Config::get('db.username', ''), (string) Config::get('db.password', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } else {
            $path = self::sqlitePath();
            $dir = dirname($path);
            if (!is_dir($dir)) {
                ensure_dir($dir);
            }
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
        }

        self::ensureSchema();

        return self::$pdo;
    }

    public static function driver(): string
    {
        self::pdo();

        return self::$driver;
    }

    public static function sqlitePath(): string
    {
        $configured = (string) Config::get('db.path', 'storage/data/mcfix.sqlite');
        if ($configured === '') {
            $configured = 'storage/data/mcfix.sqlite';
        }

        if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $configured)) {
            return $configured;
        }

        return MCFIX_ROOT . '/' . ltrim($configured, '/');
    }

    /**
     * 建表（幂等）。
     *
     * 正常情况下由 pdo() 在建立连接后调用一次。但这个方法也允许被直接调用
     * （比如命令行里的迁移脚本），所以开头自己确保一次连接 —— 否则 self::$pdo 还是 null，
     * 会以 "Call to a member function exec() on null" 这种很难看懂的方式炸掉。
     * pdo() 里有 $pdo instanceof PDO 的短路，不会递归。
     */
    public static function ensureSchema(): void
    {
        if (self::$ready) {
            return;
        }

        // 还没连上数据库：交给 pdo() 建连接，它会回头再调一次本方法。
        // 注意这里**不能**提前把 $ready 置 true，否则第二次进来会直接返回、表就永远建不出来。
        if (!self::$pdo instanceof PDO) {
            self::pdo();
            return;
        }

        self::$ready = true;

        $sqlite = self::$driver === 'sqlite';
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $text = $sqlite ? 'TEXT' : 'LONGTEXT';
        $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        $statements = [
            "CREATE TABLE IF NOT EXISTS servers (
                id VARCHAR(64) NOT NULL PRIMARY KEY,
                name VARCHAR(128) NOT NULL,
                meta {$text} NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL
            ){$engine}",

            "CREATE TABLE IF NOT EXISTS feedback (
                id {$id},
                ticket_no VARCHAR(32) NOT NULL,
                server_id VARCHAR(64) NOT NULL,
                player_name VARCHAR(64) NOT NULL,
                player_uuid VARCHAR(80) NULL,
                contact VARCHAR(128) NULL,
                category VARCHAR(48) NOT NULL,
                raw_category VARCHAR(48) NULL,
                subject VARCHAR(200) NOT NULL,
                message {$text} NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'submitted',
                severity VARCHAR(16) NOT NULL DEFAULT 'normal',
                auto_fixable INTEGER NOT NULL DEFAULT 0,
                auto_fixed INTEGER NOT NULL DEFAULT 0,
                fix_attempts INTEGER NOT NULL DEFAULT 0,
                ip_hash VARCHAR(64) NULL,
                user_agent VARCHAR(255) NULL,
                diagnosis {$text} NULL,
                verdict {$text} NULL,
                fix_plan {$text} NULL,
                fix_result {$text} NULL,
                resolution_note {$text} NULL,
                admin_note {$text} NULL,
                token_nonce VARCHAR(48) NULL,
                client_log {$text} NULL,
                client_log_name VARCHAR(160) NULL,
                client_log_source VARCHAR(16) NULL,
                client_log_size INTEGER NOT NULL DEFAULT 0,
                client_analysis {$text} NULL,
                client_issue_code VARCHAR(48) NULL,
                cross_check {$text} NULL,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                closed_at VARCHAR(32) NULL
            ){$engine}",

            "CREATE TABLE IF NOT EXISTS tasks (
                id {$id},
                feedback_id BIGINT NULL,
                server_id VARCHAR(64) NOT NULL,
                recipe VARCHAR(48) NOT NULL,
                action VARCHAR(48) NOT NULL,
                params {$text} NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'queued',
                requires_approval INTEGER NOT NULL DEFAULT 0,
                approved_by VARCHAR(64) NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                result {$text} NULL,
                error {$text} NULL,
                created_at VARCHAR(32) NOT NULL,
                queued_at VARCHAR(32) NULL,
                leased_at VARCHAR(32) NULL,
                started_at VARCHAR(32) NULL,
                finished_at VARCHAR(32) NULL,
                expires_at VARCHAR(32) NULL,
                lease_token VARCHAR(64) NULL
            ){$engine}",

            "CREATE TABLE IF NOT EXISTS events (
                id {$id},
                feedback_id BIGINT NULL,
                actor VARCHAR(32) NOT NULL,
                action VARCHAR(64) NOT NULL,
                level VARCHAR(16) NOT NULL DEFAULT 'info',
                message VARCHAR(500) NULL,
                payload {$text} NULL,
                created_at VARCHAR(32) NOT NULL
            ){$engine}",

            "CREATE TABLE IF NOT EXISTS agent_seen (
                server_id VARCHAR(64) NOT NULL PRIMARY KEY,
                agent_version VARCHAR(32) NULL,
                hostname VARCHAR(128) NULL,
                mc_online INTEGER NOT NULL DEFAULT 0,
                players INTEGER NOT NULL DEFAULT 0,
                max_players INTEGER NOT NULL DEFAULT 0,
                tps VARCHAR(16) NULL,
                process_cpu VARCHAR(16) NULL,
                process_mem VARCHAR(24) NULL,
                disk_free VARCHAR(24) NULL,
                disk_total VARCHAR(24) NULL,
                extra {$text} NULL,
                last_seen VARCHAR(32) NULL,
                first_seen VARCHAR(32) NULL,
                last_error {$text} NULL
            ){$engine}",

            "CREATE TABLE IF NOT EXISTS client_issues (
                id {$id},
                feedback_id BIGINT NULL,
                server_id VARCHAR(64) NOT NULL,
                player_name VARCHAR(64) NOT NULL,
                code VARCHAR(48) NOT NULL,
                severity VARCHAR(16) NOT NULL DEFAULT 'normal',
                source VARCHAR(16) NOT NULL DEFAULT 'client',
                detail VARCHAR(500) NULL,
                extra {$text} NULL,
                mods {$text} NULL,
                resolved INTEGER NOT NULL DEFAULT 0,
                admin_note VARCHAR(500) NULL,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL
            ){$engine}",

            "CREATE TABLE IF NOT EXISTS notifications (
                id {$id},
                feedback_id BIGINT NULL,
                server_id VARCHAR(64) NULL,
                channel VARCHAR(48) NOT NULL,
                event VARCHAR(48) NOT NULL,
                level VARCHAR(16) NOT NULL DEFAULT 'info',
                title VARCHAR(200) NULL,
                body {$text} NULL,
                link VARCHAR(500) NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'sent',
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error VARCHAR(500) NULL,
                dedupe_key VARCHAR(160) NULL,
                sent_at VARCHAR(32) NULL,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL
            ){$engine}",

            /*
             * 邮件发送队列。
             *
             * 为什么不让提交接口直接发：mail() 会阻塞好几秒，而玩家正等着响应；
             * 而且宝塔上没装 postfix 时必然失败，需要重试。
             * 交给每分钟跑一次的 cron 慢慢发，失败还会退避重试。
             *
             * to_addr 只在"还没发出去"的时候留着明文 —— 发成功或彻底失败后立刻清空，
             * 只保留 to_masked 用于界面展示。玩家邮箱不该在库里长期留存。
             */
            "CREATE TABLE IF NOT EXISTS email_jobs (
                id {$id},
                feedback_id BIGINT NULL,
                kind VARCHAR(32) NOT NULL,
                to_addr VARCHAR(200) NULL,
                to_masked VARCHAR(200) NULL,
                subject VARCHAR(200) NOT NULL,
                body_html {$text} NULL,
                body_text {$text} NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'queued',
                attempts INTEGER NOT NULL DEFAULT 0,
                next_try_at VARCHAR(32) NULL,
                last_error VARCHAR(500) NULL,
                created_at VARCHAR(32) NOT NULL,
                sent_at VARCHAR(32) NULL,
                cleaned_at VARCHAR(32) NULL
            ){$engine}",
        ];

        foreach ($statements as $sql) {
            try {
                self::$pdo->exec($sql);
            } catch (PDOException $e) {
                app_log('error', '建表失败', ['sql' => $sql, 'error' => $e->getMessage()]);
            }
        }

        // 唯一索引单独处理：MySQL 不支持 CREATE INDEX IF NOT EXISTS，重复报错忽略即可
        try {
            self::$pdo->exec('CREATE UNIQUE INDEX idx_feedback_ticket ON feedback (ticket_no)');
        } catch (PDOException $e) {
            // 已存在，正常
        }

        foreach ([
            'CREATE INDEX idx_feedback_status ON feedback (status)',
            'CREATE INDEX idx_feedback_server ON feedback (server_id)',
            'CREATE INDEX idx_feedback_client_issue ON feedback (client_issue_code)',
            'CREATE INDEX idx_tasks_status ON tasks (status)',
            'CREATE INDEX idx_tasks_server ON tasks (server_id)',
            'CREATE INDEX idx_events_ticket ON events (feedback_id)',
            'CREATE INDEX idx_client_issues_code ON client_issues (code)',
            'CREATE INDEX idx_client_issues_server ON client_issues (server_id)',
            'CREATE INDEX idx_client_issues_feedback ON client_issues (feedback_id)',
            'CREATE INDEX idx_notifications_status ON notifications (status)',
            'CREATE INDEX idx_notifications_channel ON notifications (channel)',
            'CREATE INDEX idx_notifications_feedback ON notifications (feedback_id)',
            'CREATE INDEX idx_email_jobs_status ON email_jobs (status)',
            'CREATE INDEX idx_email_jobs_feedback ON email_jobs (feedback_id)',
        ] as $sql) {
            try {
                self::$pdo->exec($sql);
            } catch (PDOException $e) {
                // 已存在，正常
            }
        }

        // 给老库补列。CREATE TABLE IF NOT EXISTS 不会动已存在的表，
        // 所以新加的字段必须单独 ALTER 一次。
        self::addColumnIfMissing('feedback', 'player_email', 'VARCHAR(200) NULL');
        self::addColumnIfMissing('feedback', 'email_purged_at', 'VARCHAR(32) NULL');
        // 日志原文按留存天数清理后记一个时间戳（见 Workflow::purgeExpiredLogs）
        self::addColumnIfMissing('feedback', 'log_purged_at', 'VARCHAR(32) NULL');
    }

    /**
     * 幂等加列。
     *
     * SQLite 和 MySQL 都支持 `ALTER TABLE ... ADD COLUMN`，但都不支持 IF NOT EXISTS，
     * 所以只能靠"重复执行报错就忽略"来做幂等 —— 注意别把真正的错误也吞掉。
     */
    public static function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        try {
            self::$pdo->exec(
                'ALTER TABLE ' . self::quoteIdentifier($table)
                . ' ADD COLUMN ' . self::quoteIdentifier($column) . ' ' . $definition
            );
        } catch (PDOException $e) {
            $message = strtolower($e->getMessage());
            $benign = strpos($message, 'duplicate column') !== false      // SQLite
                || strpos($message, 'duplicate field') !== false          // MySQL 5.7
                || strpos($message, 'already exists') !== false;          // MySQL 8 / 其它
            if (!$benign) {
                app_log('error', '加列失败', ['table' => $table, 'column' => $column, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static function (string $c): string {
            return ':' . $c;
        }, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdentifier($table),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::normalize($data));

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public static function update(string $table, array $data, array $where): int
    {
        if (!$data || !$where) {
            return 0;
        }

        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = self::quoteIdentifier($column) . ' = :set_' . $column;
        }
        $conds = [];
        foreach (array_keys($where) as $column) {
            $conds[] = self::quoteIdentifier($column) . ' = :where_' . $column;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::quoteIdentifier($table),
            implode(', ', $sets),
            implode(' AND ', $conds)
        );

        $params = [];
        foreach ($data as $key => $value) {
            $params['set_' . $key] = $value;
        }
        foreach ($where as $key => $value) {
            $params['where_' . $key] = $value;
        }

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::normalize($params));

        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed> $where
     */
    public static function delete(string $table, array $where): int
    {
        if (!$where) {
            return 0;
        }
        $conds = [];
        foreach (array_keys($where) as $column) {
            $conds[] = self::quoteIdentifier($column) . ' = :' . $column;
        }
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            self::quoteIdentifier($table),
            implode(' AND ', $conds)
        );
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::normalize($where));

        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::normalize($params));
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::normalize($params));

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<string,mixed> $params
     * @return mixed
     */
    public static function scalar(string $sql, array $params = [])
    {
        $row = self::first($sql, $params);
        if ($row === null) {
            return null;
        }
        $values = array_values($row);

        return $values[0] ?? null;
    }

    /**
     * @param array<string,mixed> $params
     */
    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(self::normalize($params));

        return $stmt->rowCount();
    }

    public static function count(string $table, array $where = []): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM ' . self::quoteIdentifier($table);
        $params = [];
        if ($where) {
            $conds = [];
            foreach (array_keys($where) as $column) {
                $conds[] = self::quoteIdentifier($column) . ' = :' . $column;
                $params[$column] = $where[$column];
            }
            $sql .= ' WHERE ' . implode(' AND ', $conds);
        }

        return (int) self::scalar($sql, $params);
    }

    /**
     * SQLite 不认识 bool，统一转换，避免 true 变成 "1" 之外的坑。
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function normalize(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $value ? 1 : 0;
            } elseif (is_array($value) || is_object($value)) {
                $params[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $params;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        /*
         * 白名单才是真正靠谱的那一道（V13）。
         *
         * 原来写的是 str_replace('"', '', $identifier) —— "删掉引号"不是转义，
         * 它只是让注入的字符消失。而真正的转义规则还依赖驱动：双写 "" 在 SQLite
         * 和 ANSI_QUOTES 下正确，但在 MySQL 默认 sql_mode 下双引号是**字符串
         * 定界符**，双写并不构成标识符转义。
         *
         * 报告逐条核对了全部 46 处 Db::insert/update/delete/count 调用点，
         * 表名与数组键清一色是字面量 → 当前不可达。但"不可达"依赖的是调用点的
         * 写法，不是这行的正确性。把白名单加在这里，将来新增调用点也不会出事。
         * （标识符是代码里写死的表名/列名，正常绝不会含引号 —— 一旦含了就说明
         *   有东西在拿用户输入拼 SQL，这时抛异常比悄悄放行更安全。）
         */
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException('非法标识符：' . $identifier);
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * 数据库体积与工单数，用于后台首页。
     *
     * @return array<string,mixed>
     */
    public static function stats(): array
    {
        $out = ['driver' => self::driver(), 'size' => 0, 'path' => ''];
        if (self::$driver === 'sqlite') {
            $path = self::sqlitePath();
            $out['path'] = $path;
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    $out['size'] += (int) filesize($file);
                }
            }
        }

        return $out;
    }
}
