<?php
/**
 * TicketIndex — SQLite FTS5 search index for TechSupport tickets.
 *
 * Shadows the JSON ticket files with a searchable SQLite database.
 * Provides full-text search, filtering, sorting, and duplicate detection.
 *
 * Usage:
 *   $idx = new TicketIndex('/path/to/support_tickets');
 *   $idx->rebuild();                          // Full index rebuild from JSON files
 *   $idx->indexTicket($ticketData);           // Index or update a single ticket
 *   $idx->removeTicket('TS-20260303-001');    // Remove from index
 *   $results = $idx->search('queue GHL');     // Full-text search
 *   $similar = $idx->findSimilar($ticket);    // Duplicate/related detection
 *   $results = $idx->list($filters, $sort);   // Filtered list (replaces file scan)
 */

class TicketIndex {

    private string $dataDir;
    private string $dbPath;
    private ?\PDO $db = null;

    public function __construct(string $dataDir) {
        $this->dataDir = rtrim($dataDir, '/');
        $this->dbPath = $this->dataDir . '/search_index.sqlite';
    }

    // ── Connection ───────────────────────────────────────────────

    private function db(): \PDO {
        if ($this->db !== null) return $this->db;

        $isNew = !file_exists($this->dbPath);
        $this->db = new \PDO('sqlite:' . $this->dbPath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA busy_timeout = 5000');

        if ($isNew) {
            $this->createSchema();
        }

        return $this->db;
    }

    private function createSchema(): void {
        $db = $this->db;

        // Main tickets table — denormalized for fast queries
        $db->exec("CREATE TABLE IF NOT EXISTS tickets (
            id             TEXT PRIMARY KEY,
            subject        TEXT NOT NULL DEFAULT '',
            description    TEXT NOT NULL DEFAULT '',
            domain         TEXT NOT NULL DEFAULT '',
            category       TEXT NOT NULL DEFAULT '',
            urgency        TEXT NOT NULL DEFAULT '',
            status         TEXT NOT NULL DEFAULT '',
            affected_areas TEXT NOT NULL DEFAULT '',
            notes_text     TEXT NOT NULL DEFAULT '',
            resolution     TEXT NOT NULL DEFAULT '',
            user_name      TEXT NOT NULL DEFAULT '',
            user_role      TEXT NOT NULL DEFAULT '',
            agent_task_id  TEXT NOT NULL DEFAULT '',
            submitted_at   TEXT NOT NULL DEFAULT '',
            updated_at     TEXT NOT NULL DEFAULT '',
            resolved_at    TEXT NOT NULL DEFAULT '',
            location       TEXT NOT NULL DEFAULT '',
            json_hash      TEXT NOT NULL DEFAULT ''
        )");

        // FTS5 virtual table for full-text search
        $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS tickets_fts USING fts5(
            id,
            subject,
            description,
            domain,
            category,
            affected_areas,
            notes_text,
            resolution,
            user_name,
            content='tickets',
            content_rowid='rowid',
            tokenize='unicode61 remove_diacritics 2'
        )");

        // Triggers to keep FTS in sync with main table
        $db->exec("CREATE TRIGGER IF NOT EXISTS tickets_ai AFTER INSERT ON tickets BEGIN
            INSERT INTO tickets_fts(rowid, id, subject, description, domain, category, affected_areas, notes_text, resolution, user_name)
            VALUES (new.rowid, new.id, new.subject, new.description, new.domain, new.category, new.affected_areas, new.notes_text, new.resolution, new.user_name);
        END");

        $db->exec("CREATE TRIGGER IF NOT EXISTS tickets_ad AFTER DELETE ON tickets BEGIN
            INSERT INTO tickets_fts(tickets_fts, rowid, id, subject, description, domain, category, affected_areas, notes_text, resolution, user_name)
            VALUES ('delete', old.rowid, old.id, old.subject, old.description, old.domain, old.category, old.affected_areas, old.notes_text, old.resolution, old.user_name);
        END");

        $db->exec("CREATE TRIGGER IF NOT EXISTS tickets_au AFTER UPDATE ON tickets BEGIN
            INSERT INTO tickets_fts(tickets_fts, rowid, id, subject, description, domain, category, affected_areas, notes_text, resolution, user_name)
            VALUES ('delete', old.rowid, old.id, old.subject, old.description, old.domain, old.category, old.affected_areas, old.notes_text, old.resolution, old.user_name);
            INSERT INTO tickets_fts(rowid, id, subject, description, domain, category, affected_areas, notes_text, resolution, user_name)
            VALUES (new.rowid, new.id, new.subject, new.description, new.domain, new.category, new.affected_areas, new.notes_text, new.resolution, new.user_name);
        END");

        // Indexes for common filter/sort queries
        $db->exec("CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_tickets_domain ON tickets(domain)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_tickets_category ON tickets(category)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_tickets_urgency ON tickets(urgency)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_tickets_submitted ON tickets(submitted_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_tickets_updated ON tickets(updated_at)");

        // Metadata table for index state
        $db->exec("CREATE TABLE IF NOT EXISTS meta (
            key   TEXT PRIMARY KEY,
            value TEXT
        )");
    }

    // ── Rebuild ──────────────────────────────────────────────────

    /**
     * Full rebuild from JSON files. Scans inbox/, archive/, and root .json files.
     * Returns count of indexed tickets.
     */
    public function rebuild(): int {
        $db = $this->db();
        $db->exec('BEGIN');

        // Clear everything
        $db->exec('DELETE FROM tickets');
        // Rebuild FTS content
        $db->exec("INSERT INTO tickets_fts(tickets_fts) VALUES('rebuild')");

        $count = 0;
        $seen = []; // Track IDs to skip root duplicates
        // Priority order: inbox first, then archive, then root
        $dirs = [
            $this->dataDir . '/inbox'  => 'inbox',
            $this->dataDir . '/archive' => 'archive',
            $this->dataDir              => 'root',
        ];

        foreach ($dirs as $dir => $location) {
            if (!is_dir($dir)) continue;
            $files = glob($dir . '/TS-*.json') ?: [];
            foreach ($files as $file) {
                $json = @file_get_contents($file);
                if ($json === false) continue;
                $ticket = @json_decode($json, true);
                if (!is_array($ticket) || empty($ticket['id'])) continue;

                // Skip if already indexed from a higher-priority directory
                if (isset($seen[$ticket['id']])) continue;
                $seen[$ticket['id']] = $location;

                $this->insertTicket($ticket, $location, md5($json));
                $count++;
            }
        }

        $db->exec("REPLACE INTO meta (key, value) VALUES ('last_rebuild', '" . date('c') . "')");
        $db->exec("REPLACE INTO meta (key, value) VALUES ('ticket_count', '$count')");
        $db->exec('COMMIT');

        return $count;
    }

    // ── Single Ticket CRUD ───────────────────────────────────────

    /**
     * Index or update a single ticket. Detects location automatically if not specified.
     */
    public function indexTicket(array $ticket, ?string $location = null): bool {
        if (empty($ticket['id'])) return false;

        if ($location === null) {
            $location = $this->detectLocation($ticket['id']);
        }

        $json = json_encode($ticket);
        $hash = md5($json);

        $db = $this->db();

        // Check if already indexed with same hash (skip if unchanged)
        $existing = $db->prepare('SELECT json_hash FROM tickets WHERE id = ?');
        $existing->execute([$ticket['id']]);
        $row = $existing->fetch();
        if ($row && $row['json_hash'] === $hash) return true; // No change

        $db->exec('BEGIN');
        $db->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticket['id']]);
        $this->insertTicket($ticket, $location, $hash);
        $db->exec('COMMIT');

        return true;
    }

    /**
     * Remove a ticket from the index.
     */
    public function removeTicket(string $ticketId): bool {
        $db = $this->db();
        $stmt = $db->prepare('DELETE FROM tickets WHERE id = ?');
        $stmt->execute([$ticketId]);
        return $stmt->rowCount() > 0;
    }

    // ── Search ───────────────────────────────────────────────────

    /**
     * Full-text search across all indexed fields.
     * Returns array of matching tickets with relevance score.
     */
    public function search(string $query, array $filters = [], int $limit = 50, int $offset = 0): array {
        $db = $this->db();
        $this->ensureIndex();

        $query = trim($query);
        if ($query === '') {
            return $this->list($filters, 'updated_at DESC', $limit, $offset);
        }

        // Prepare FTS query — add * suffix for prefix matching
        $ftsQuery = $this->prepareFtsQuery($query);

        $where = [];
        $params = [];

        // Status filter
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['status']), '?'));
                $where[] = "t.status IN ($placeholders)";
                $params = array_merge($params, $filters['status']);
            } else {
                $where[] = 't.status = ?';
                $params[] = $filters['status'];
            }
        }

        // Domain filter
        if (!empty($filters['domain'])) {
            $where[] = 't.domain = ?';
            $params[] = $filters['domain'];
        }

        // Category filter
        if (!empty($filters['category'])) {
            $where[] = 't.category = ?';
            $params[] = $filters['category'];
        }

        // Location filter (inbox/archive/root)
        if (!empty($filters['location'])) {
            $where[] = 't.location = ?';
            $params[] = $filters['location'];
        }

        $whereClause = $where ? ' AND ' . implode(' AND ', $where) : '';

        $sql = "SELECT t.*, fts.rank
                FROM tickets_fts fts
                JOIN tickets t ON t.rowid = fts.rowid
                WHERE tickets_fts MATCH ?
                $whereClause
                ORDER BY fts.rank
                LIMIT ? OFFSET ?";

        $params = array_merge([$ftsQuery], $params, [$limit, $offset]);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Filtered list without full-text search (replaces file scan).
     */
    public function list(array $filters = [], string $orderBy = 'updated_at DESC', int $limit = 200, int $offset = 0): array {
        $db = $this->db();
        $this->ensureIndex();

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['status']), '?'));
                $where[] = "status IN ($placeholders)";
                $params = array_merge($params, $filters['status']);
            } else {
                $where[] = 'status = ?';
                $params[] = $filters['status'];
            }
        }

        if (!empty($filters['domain'])) {
            $where[] = 'domain = ?';
            $params[] = $filters['domain'];
        }

        if (!empty($filters['category'])) {
            $where[] = 'category = ?';
            $params[] = $filters['category'];
        }

        if (!empty($filters['urgency'])) {
            $where[] = 'urgency = ?';
            $params[] = $filters['urgency'];
        }

        if (!empty($filters['location'])) {
            $where[] = 'location = ?';
            $params[] = $filters['location'];
        }

        // Exclude statuses (e.g., hide archived)
        if (!empty($filters['exclude_status'])) {
            $excl = (array)$filters['exclude_status'];
            $placeholders = implode(',', array_fill(0, count($excl), '?'));
            $where[] = "status NOT IN ($placeholders)";
            $params = array_merge($params, $excl);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Whitelist sort columns to prevent injection
        $allowedSorts = [
            'submitted_at', 'updated_at', 'resolved_at', 'subject',
            'domain', 'status', 'urgency', 'category', 'id',
        ];
        $sortParts = explode(' ', $orderBy, 2);
        $sortCol = $sortParts[0];
        $sortDir = strtoupper($sortParts[1] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        if (!in_array($sortCol, $allowedSorts)) {
            $sortCol = 'updated_at';
        }

        $sql = "SELECT * FROM tickets $whereClause ORDER BY $sortCol $sortDir LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Count tickets matching filters.
     */
    public function count(array $filters = []): int {
        $db = $this->db();
        $this->ensureIndex();

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['status']), '?'));
                $where[] = "status IN ($placeholders)";
                $params = array_merge($params, $filters['status']);
            } else {
                $where[] = 'status = ?';
                $params[] = $filters['status'];
            }
        }

        if (!empty($filters['domain'])) {
            $where[] = 'domain = ?';
            $params[] = $filters['domain'];
        }

        if (!empty($filters['location'])) {
            $where[] = 'location = ?';
            $params[] = $filters['location'];
        }

        if (!empty($filters['exclude_status'])) {
            $excl = (array)$filters['exclude_status'];
            $placeholders = implode(',', array_fill(0, count($excl), '?'));
            $where[] = "status NOT IN ($placeholders)";
            $params = array_merge($params, $excl);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM tickets $whereClause");
        $stmt->execute($params);
        return (int)$stmt->fetch()['cnt'];
    }

    // ── Similar / Duplicate Detection ────────────────────────────

    /**
     * Find tickets similar to a given ticket (by subject + description + domain).
     * Useful for duplicate detection and "Similar Resolved Issues" panel.
     */
    public function findSimilar(array $ticket, int $limit = 5, bool $resolvedOnly = false): array {
        $db = $this->db();
        $this->ensureIndex();

        // Build search terms from subject + key description words
        $terms = $this->extractKeyTerms($ticket);
        if (empty($terms)) return [];

        $ftsQuery = implode(' OR ', array_map(function($t) {
            return '"' . str_replace('"', '', $t) . '"';
        }, $terms));

        $where = '';
        $params = [$ftsQuery];

        // Exclude the ticket itself
        if (!empty($ticket['id'])) {
            $where .= ' AND t.id != ?';
            $params[] = $ticket['id'];
        }

        // Only resolved/archived if requested
        if ($resolvedOnly) {
            $where .= " AND t.status IN ('resolved', 'archived')";
        }

        // Boost same-domain matches
        $sql = "SELECT t.*, fts.rank,
                    CASE WHEN t.domain = ? THEN 1 ELSE 0 END as domain_match
                FROM tickets_fts fts
                JOIN tickets t ON t.rowid = fts.rowid
                WHERE tickets_fts MATCH ?
                $where
                ORDER BY domain_match DESC, fts.rank
                LIMIT ?";

        $domainParam = $ticket['domain'] ?? '';
        $allParams = array_merge([$domainParam, $ftsQuery], array_slice($params, 1), [$limit]);

        $stmt = $db->prepare($sql);
        $stmt->execute($allParams);
        return $stmt->fetchAll();
    }

    // ── Stats ────────────────────────────────────────────────────

    /**
     * Get ticket statistics from the index.
     */
    public function stats(): array {
        $db = $this->db();
        $this->ensureIndex();

        $statusCounts = $db->query("SELECT status, COUNT(*) as cnt FROM tickets GROUP BY status ORDER BY cnt DESC")->fetchAll();
        $domainCounts = $db->query("SELECT domain, COUNT(*) as cnt FROM tickets WHERE domain != '' GROUP BY domain ORDER BY cnt DESC LIMIT 20")->fetchAll();
        $categoryCounts = $db->query("SELECT category, COUNT(*) as cnt FROM tickets WHERE category != '' GROUP BY category ORDER BY cnt DESC")->fetchAll();
        $total = $db->query("SELECT COUNT(*) as cnt FROM tickets")->fetch()['cnt'];

        $meta = [];
        $metaRows = $db->query("SELECT key, value FROM meta")->fetchAll();
        foreach ($metaRows as $r) $meta[$r['key']] = $r['value'];

        return [
            'total' => (int)$total,
            'by_status' => $statusCounts,
            'by_domain' => $domainCounts,
            'by_category' => $categoryCounts,
            'last_rebuild' => $meta['last_rebuild'] ?? null,
        ];
    }

    // ── Internal Helpers ─────────────────────────────────────────

    private function insertTicket(array $t, string $location, string $hash): void {
        $db = $this->db;

        // Flatten notes into searchable text
        $notesText = '';
        if (!empty($t['notes']) && is_array($t['notes'])) {
            $parts = [];
            foreach ($t['notes'] as $note) {
                if (is_array($note)) {
                    $parts[] = ($note['text'] ?? '') . ' ' . ($note['by'] ?? '');
                } elseif (is_string($note)) {
                    $parts[] = $note;
                }
            }
            $notesText = implode(' ', $parts);
        }

        // Flatten affected_areas array
        $areas = '';
        if (!empty($t['affected_areas']) && is_array($t['affected_areas'])) {
            $areas = implode(', ', $t['affected_areas']);
        }

        $stmt = $db->prepare("INSERT INTO tickets
            (id, subject, description, domain, category, urgency, status,
             affected_areas, notes_text, resolution, user_name, user_role,
             agent_task_id, submitted_at, updated_at, resolved_at, location, json_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $t['id'],
            $t['subject'] ?? '',
            $t['description'] ?? '',
            $t['domain'] ?? $t['site_origin'] ?? '',
            $t['category'] ?? '',
            $t['urgency'] ?? '',
            $t['status'] ?? '',
            $areas,
            $notesText,
            $t['resolution'] ?? '',
            $t['user']['display_name'] ?? $t['user']['username'] ?? '',
            $t['user']['role'] ?? '',
            $t['agent_task_id'] ?? '',
            $t['submitted_at'] ?? '',
            $t['updated_at'] ?? '',
            $t['resolved_at'] ?? '',
            $location,
            $hash,
        ]);
    }

    /**
     * Detect which directory a ticket lives in.
     */
    private function detectLocation(string $ticketId): string {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $ticketId);
        if (is_file($this->dataDir . '/inbox/' . $safe . '.json')) return 'inbox';
        if (is_file($this->dataDir . '/archive/' . $safe . '.json')) return 'archive';
        if (is_file($this->dataDir . '/' . $safe . '.json')) return 'root';
        return 'unknown';
    }

    /**
     * Extract key search terms from a ticket for similarity matching.
     */
    private function extractKeyTerms(array $ticket): array {
        $text = ($ticket['subject'] ?? '') . ' ' . ($ticket['description'] ?? '');
        // Remove common stop words and short tokens
        $stopWords = ['the','a','an','is','are','was','were','be','been','to','of','in','for',
                       'and','or','not','on','at','by','with','from','as','it','this','that',
                       'we','need','should','would','could','will','can','do','has','have',
                       'had','but','if','so','than','then','into','up','out','no','about'];

        // Tokenize
        $words = preg_split('/[\s\-_\/\.\,\;\:\!\?\(\)\[\]\{\}]+/', strtolower($text));
        $words = array_filter($words, function($w) use ($stopWords) {
            return strlen($w) >= 3 && !in_array($w, $stopWords) && !is_numeric($w);
        });

        // Take most significant terms (unique, first N)
        $unique = array_unique($words);
        return array_slice(array_values($unique), 0, 8);
    }

    /**
     * Prepare a user query for FTS5 MATCH syntax.
     * Handles simple keyword searches safely.
     */
    private function prepareFtsQuery(string $query): string {
        // If it looks like a ticket ID, search that field specifically (quoted for hyphens)
        if (preg_match('/^TS-\d{8}-\d{3}$/i', $query)) {
            return 'id:"' . $query . '"';
        }

        // Split into tokens, wrap each for safe FTS5 matching
        $tokens = preg_split('/\s+/', trim($query));
        $tokens = array_filter($tokens, function($t) { return strlen($t) >= 2; });

        if (empty($tokens)) return '""';

        // Each token gets prefix matching with *
        $parts = array_map(function($token) {
            // Remove FTS special chars
            $clean = preg_replace('/["\'\*\(\)\:\^]/', '', $token);
            if ($clean === '') return null;
            return '"' . $clean . '"*';
        }, $tokens);

        $parts = array_filter($parts);
        return implode(' AND ', $parts);
    }

    /**
     * Ensure index exists — auto-rebuild if DB is empty or missing.
     */
    private function ensureIndex(): void {
        $db = $this->db();
        $count = $db->query("SELECT COUNT(*) as cnt FROM tickets")->fetch()['cnt'];
        if ((int)$count === 0) {
            $this->rebuild();
        }
    }

    /**
     * Close the database connection.
     */
    public function close(): void {
        $this->db = null;
    }
}
