<?php
/**
 * MonthlyDataModel — all database access for the Monthly Data feature.
 */
class MonthlyDataModel {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    public function fetchRow(string $sql, string $types, ...$vals): ?array {
        $s = $this->conn->prepare($sql);
        if (!$s) { return null; }
        $s->bind_param($types, ...$vals);
        $s->execute();
        $r = $s->get_result();
        $row = $r->fetch_assoc();
        $r->free();
        $s->close();
        return $row ?: null;
    }

    public function fetchAll(string $sql, string $types = '', ...$vals): array {
        if ($types === '') {
            $r = $this->conn->query($sql);
            $rows = [];
            while ($row = $r->fetch_assoc()) $rows[] = $row;
            $r->free();
            return $rows;
        }
        $s = $this->conn->prepare($sql);
        $s->bind_param($types, ...$vals);
        $s->execute();
        $r = $s->get_result();
        $rows = [];
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        $r->free();
        $s->close();
        return $rows;
    }

    public function execute(string $sql, string $types, ...$vals): bool {
        $s = $this->conn->prepare($sql);
        $s->bind_param($types, ...$vals);
        $ok = $s->execute();
        $s->close();
                return $ok;
    }

    // ── Domain queries (moved from add_data.php) ──

    public function getMonth(int $month_id): ?array {
        return $this->fetchRow("SELECT * FROM months WHERE id=?", "i", $month_id);
    }

    public function getUser(int $user_id): ?array {
        return $this->fetchRow("SELECT u.*, r.name AS role_name, r.description AS role_description FROM users u LEFT JOIN roles r ON u.role_id=r.id WHERE u.id=?", "i", $user_id);
    }

    public function getManagerByRole(string $role_name): ?array {
        return $this->fetchRow("SELECT u.id, u.email, u.first_name, u.last_name, u.username FROM users u JOIN roles r ON u.role_id=r.id WHERE r.name=? AND u.email IS NOT NULL AND u.email!='' LIMIT 1", "s", $role_name);
    }

    public function getAdminUsers(): array {
        return $this->fetchAll("SELECT u.email, u.first_name, u.last_name, u.username FROM users u JOIN roles r ON u.role_id=r.id WHERE r.name='admin' AND u.email IS NOT NULL AND u.email != '' ");
    }

    public function getApproval(int $month_id, string $role): ?array {
        return $this->fetchRow("SELECT * FROM month_approvals WHERE month_id=? AND manager_role=?", "is", $month_id, $role);
    }

    public function getValidApproval(int $month_id, string $role, string $token): ?array {
        return $this->fetchRow("SELECT * FROM month_approvals WHERE month_id=? AND manager_role=? AND approval_token=? AND token_expires_at>NOW()", "iss", $month_id, $role, $token);
    }

    public function updateApprovalStatus(int $approval_id, string $status, ?string $reason = null, ?int $approved_by = null): bool {
        if ($status === 'approved') {
            return $this->execute("UPDATE month_approvals SET status='approved', approved_at=NOW(), approved_by=? WHERE id=?", "ii", $approved_by, $approval_id);
        }
        return $this->execute("UPDATE month_approvals SET status='rejected', rejection_reason=?, approved_at=NOW(), approved_by=? WHERE id=?", "sii", $reason, $approved_by, $approval_id);
    }

    public function upsertApprovalToken(int $month_id, string $role, string $token, string $expiry): bool {
        $ex = $this->getApproval($month_id, $role);
        if ($ex) {
            return $this->execute("UPDATE month_approvals SET approval_token=?, token_expires_at=?, notified_at=NOW(), status='notified' WHERE month_id=? AND manager_role=?", "ssis", $token, $expiry, $month_id, $role);
        }
        return $this->execute("INSERT INTO month_approvals (month_id, manager_role, approval_token, token_expires_at, notified_at, status) VALUES(?,?,?,?,NOW(),'notified')", "isss", $month_id, $role, $token, $expiry);
    }

    public function upsertDirectApproval(int $month_id, string $role, int $user_id): bool {
        $ex = $this->getApproval($month_id, $role);
        if ($ex) {
            return $this->execute("UPDATE month_approvals SET status='approved', approved_at=NOW(), approved_by=? WHERE month_id=? AND manager_role=?", "iis", $user_id, $month_id, $role);
        }
        return $this->execute("INSERT INTO month_approvals (month_id, manager_role, status, approved_at, approved_by, notified_at) VALUES(?,?,'approved',NOW(),?,NOW())", "isi", $month_id, $role, $user_id);
    }

    public function getCategoriesWithParams(int $role_id, bool $is_admin): array {
        if ($is_admin) {
            $s = $this->conn->prepare("SELECT DISTINCT pc.* FROM parameter_categories pc JOIN parameters p ON pc.id=p.category_id ORDER BY pc.display_order");
            $s->execute();
        } else {
            $s = $this->conn->prepare("SELECT DISTINCT pc.* FROM parameter_categories pc JOIN parameters p ON pc.id=p.category_id JOIN role_parameter_assignments rpa ON p.id=rpa.parameter_id WHERE rpa.role_id=? ORDER BY pc.display_order");
            $s->bind_param("i", $role_id);
            $s->execute();
        }
        $r = $s->get_result(); $cats = [];
        while ($row = $r->fetch_assoc()) $cats[] = $row;
        $r->free(); $s->close();

        $out = [];
        foreach ($cats as $cat) {
            $params = $is_admin
                ? $this->fetchAll("SELECT p.* FROM parameters p WHERE p.category_id=?", "i", $cat['id'])
                : $this->fetchAll("SELECT p.* FROM parameters p JOIN role_parameter_assignments rpa ON p.id=rpa.parameter_id WHERE rpa.role_id=? AND p.category_id=?", "ii", $role_id, $cat['id']);
            if (!empty($params)) $out[] = ['category' => $cat, 'parameters' => $params];
        }
        return $out;
    }

    public function isSaved(int $month_id, int $cat_id, int $role_id, bool $is_admin): bool {
        $ids = $is_admin
            ? array_column($this->fetchAll("SELECT id FROM parameters WHERE category_id=?", "i", $cat_id), 'id')
            : array_column($this->fetchAll("SELECT p.id FROM parameters p JOIN role_parameter_assignments rpa ON p.id=rpa.parameter_id WHERE rpa.role_id=? AND p.category_id=?", "ii", $role_id, $cat_id), 'id');
        if (empty($ids)) return false;
        $in = implode(',', array_map('intval', $ids));
        $row = $this->fetchRow("SELECT COUNT(*) AS n FROM monthly_data WHERE month_id=? AND parameter_id IN($in)", "i", $month_id);
        return $row && $row['n'] > 0;
    }

    public function catsFilled(int $month_id, array $cats): bool {
        if (empty($cats)) return false;
        $filled = 0;
        foreach ($cats as $cid) {
            $row = $this->fetchRow("SELECT COUNT(*) AS t, SUM(CASE WHEN md.value IS NULL OR TRIM(md.value)='' THEN 1 ELSE 0 END) AS e FROM parameters p LEFT JOIN monthly_data md ON p.id=md.parameter_id AND md.month_id=? WHERE p.category_id=?", "ii", $month_id, $cid);
            if ($row && $row['t'] > 0 && $row['e'] == 0) $filled++;
        }
        return $filled === count($cats);
    }

    public function validateSection(int $month_id, int $cat_id): true|array {
        $rows = $this->fetchAll("SELECT p.code, p.label, p.required, md.value FROM parameters p LEFT JOIN monthly_data md ON p.id=md.parameter_id AND md.month_id=? WHERE p.category_id=?", "ii", $month_id, $cat_id);
        $miss = []; $empty = [];
        foreach ($rows as $r) {
            $v = trim($r['value'] ?? '');
            $e = $v === '';
            if ($r['required'] && $e) $miss[] = ['code' => $r['code'], 'label' => $r['label']];
            if ($e) $empty[] = ['code' => $r['code'], 'label' => $r['label'], 'required' => $r['required']];
        }
        return (!empty($miss) || !empty($empty)) ? ['missing_required' => $miss, 'empty_values' => $empty] : true;
    }

    public function getParameterIdByCode(string $code): ?array {
        return $this->fetchRow("SELECT id FROM parameters WHERE code=?", "s", $code);
    }

    public function upsertValue(int $month_id, int $parameter_id, string $value): bool {
        return $this->execute("INSERT INTO monthly_data (month_id, parameter_id, value) VALUES(?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", "iis", $month_id, $parameter_id, $value);
    }

    public function saveSection(int $month_id, array $data): void {
        foreach ($data as $c => $v) {
            $pm = $this->fetchRow("SELECT id FROM parameters WHERE code=?", "s", $c);
            if ($pm) {
                $this->execute("INSERT INTO monthly_data (month_id, parameter_id, value) VALUES(?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", "iis", $month_id, $pm['id'], $v);
            }
        }
    }

    public function normalizeEmptyValues(int $month_id): bool {
        return $this->execute("UPDATE monthly_data md JOIN parameters p ON md.parameter_id=p.id SET md.value='-' WHERE md.month_id=? AND (md.value='' OR md.value IS NULL)", "i", $month_id);
    }

    public function submitMonth(int $month_id): bool {
        return $this->execute("UPDATE months SET status='submitted' WHERE id=?", "i", $month_id);
    }

    public function getExistingData(int $month_id): array {
        $rows = $this->fetchAll("SELECT p.code, md.value FROM monthly_data md JOIN parameters p ON md.parameter_id=p.id WHERE md.month_id=?", "i", $month_id);
        $out = [];
        foreach ($rows as $r) $out[$r['code']] = $r['value'];
        return $out;
    }
}
