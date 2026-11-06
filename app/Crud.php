<?php
/**
 * MediStock Cloud — config-driven CRUD engine for admin modules.
 *
 * Each module page declares its table, fields and list columns; the engine
 * renders search + pagination + sorting, create/edit forms and delete —
 * always with CSRF protection, PDO prepared statements and, for tenant
 * modules, automatic tenant_id scoping on every query (isolation).
 */
final class Crud
{
    /**
     * $cfg keys:
     *  title, icon, table, permission, tenant (bool), base (path prefix for links)
     *  columns: [field => label] shown in the list
     *  fields:  [field => ['label','type','required','options','options_sql','default','help','step']]
     *  search:  [field, ...] searchable columns
     *  display: [field => callable(array $row): string] custom cell renderers
     *  order:   default ORDER BY clause
     *  before_save: callable(array $data, ?int $id): array  (may throw)
     *  after_save:  callable(int $id, array $data): void
     */
    public static function handle(array $cfg): void
    {
        $user = require_permission($cfg['permission']);
        $tenantScoped = ($cfg['tenant'] ?? true) && !is_super_admin();
        $tid = $tenantScoped ? tenant_id() : null;
        $pdo = db();
        $table = $cfg['table'];
        $base = $cfg['base'] ?? basename($_SERVER['SCRIPT_NAME']);
        $action = (string)input('action', 'list');
        $id = (int)input('id', 0);

        $where = ['deleted_at IS NULL'];
        $params = [];
        if ($tenantScoped) {
            $where[] = 'tenant_id = ?';
            $params[] = $tid;
        }
        $whereSql = implode(' AND ', $where);

        /* ---------------------------------------------------------- POST */
        if (is_post()) {
            csrf_check();
            $op = (string)input('_op', '');

            if ($op === 'delete' && $id > 0) {
                $stmt = $pdo->prepare("UPDATE {$table} SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?" . ($tenantScoped ? ' AND tenant_id = ?' : ''));
                $stmt->execute($tenantScoped ? [$id, $tid] : [$id]);
                audit_log($table, $id, 'deleted');
                flash('success', $cfg['title'] . ' record deleted.');
                redirect($base);
            }

            if ($op === 'save') {
                $data = [];
                foreach ($cfg['fields'] as $field => $f) {
                    $data[$field] = input($field, $f['default'] ?? null);
                    if (($f['type'] ?? '') === 'checkbox') {
                        $data[$field] = isset($_POST[$field]) ? 1 : 0;
                    }
                }
                $rules = [];
                foreach ($cfg['fields'] as $field => $f) {
                    if (!empty($f['required'])) {
                        $rules[$field] = 'required';
                    }
                }
                $errors = validate($data, $rules);

                // File fields (type=file, e.g. a medicine photo): validate and
                // store under public/assets/uploads; the DB keeps the path
                // relative to public/assets (render with asset()). On edit,
                // the existing file is kept when no new one is uploaded.
                foreach ($cfg['fields'] as $field => $f) {
                    if (($f['type'] ?? '') !== 'file') {
                        continue;
                    }
                    $data[$field] = null;
                    if ($id > 0) {
                        $cur = $pdo->prepare("SELECT {$field} FROM {$table} WHERE id = ?");
                        $cur->execute([$id]);
                        $data[$field] = $cur->fetchColumn() ?: null;
                    }
                    if (isset($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                            $errors[] = 'Only JPG, PNG, GIF or WEBP images are allowed.';
                        } elseif ((int)$_FILES[$field]['size'] > 2 * 1024 * 1024) {
                            $errors[] = 'Images must be 2MB or smaller.';
                        } else {
                            $name = $field . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
                            if (move_uploaded_file((string)$_FILES[$field]['tmp_name'], dirname(__DIR__) . '/public/assets/uploads/' . $name)) {
                                $data[$field] = 'uploads/' . $name;
                            } else {
                                $errors[] = 'Image upload failed.';
                            }
                        }
                    }
                }

                if (isset($cfg['before_save'])) {
                    try {
                        $data = ($cfg['before_save'])($data, $id ?: null);
                    } catch (Throwable $ex) {
                        $errors[] = $ex->getMessage();
                    }
                }
                if ($errors) {
                    flash('error', implode(' ', $errors));
                    redirect($base . '?action=' . ($id ? 'edit&id=' . $id : 'new'));
                }

                if ($tenantScoped) {
                    $data['tenant_id'] = $tid;
                }
                if ($id > 0) {
                    $sets = implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($data)));
                    $stmt = $pdo->prepare("UPDATE {$table} SET {$sets}, updated_at = NOW() WHERE id = ?" . ($tenantScoped ? ' AND tenant_id = ?' : ''));
                    $stmt->execute([...array_values($data), $id, ...($tenantScoped ? [$tid] : [])]);
                    audit_log($table, $id, 'updated', null, $data);
                    flash('success', $cfg['title'] . ' record updated.');
                } else {
                    $cols = implode(', ', array_keys($data));
                    $marks = implode(', ', array_fill(0, count($data), '?'));
                    $stmt = $pdo->prepare("INSERT INTO {$table} ({$cols}, created_at, updated_at) VALUES ({$marks}, NOW(), NOW())");
                    $stmt->execute(array_values($data));
                    $id = (int)$pdo->lastInsertId();
                    audit_log($table, $id, 'created', null, $data);
                    flash('success', $cfg['title'] . ' record created.');
                }
                if (isset($cfg['after_save'])) {
                    ($cfg['after_save'])($id, $data);
                }
                redirect($base);
            }
        }

        /* ------------------------------------------------------ Form view */
        if ($action === 'new' || $action === 'edit') {
            $row = [];
            if ($action === 'edit' && $id > 0) {
                $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? AND {$whereSql}");
                $stmt->execute([$id, ...$params]);
                $row = $stmt->fetch() ?: [];
                if (!$row) {
                    flash('error', 'Record not found.');
                    redirect($base);
                }
            }
            echo '<div class="d-flex justify-content-between align-items-center mb-3">'
                . '<h4 class="mb-0">' . ($action === 'edit' ? 'Edit ' : 'New ') . e($cfg['title']) . '</h4>'
                . '<a class="btn btn-outline-secondary btn-sm" href="' . e($base) . '">Back to list</a></div>';
            echo '<div class="card shadow-sm"><div class="card-body"><form method="post" enctype="multipart/form-data">'
                . csrf_field()
                . '<input type="hidden" name="_op" value="save"><div class="row g-3">';
            foreach ($cfg['fields'] as $field => $f) {
                $value = $row[$field] ?? ($f['default'] ?? '');
                $type = $f['type'] ?? 'text';
                $label = '<label class="form-label">' . e($f['label'] ?? ucfirst(str_replace('_', ' ', $field))) . (!empty($f['required']) ? ' *' : '') . '</label>';
                $col = $f['col'] ?? 'col-md-6';
                echo '<div class="' . e($col) . '">' . $label;
                if ($type === 'select') {
                    $options = $f['options'] ?? [];
                    if (isset($f['options_sql'])) {
                        $q = $pdo->prepare($f['options_sql']);
                        $q->execute($tenantScoped ? [$tid] : []);
                        foreach ($q->fetchAll() as $o) {
                            $options[$o['id']] = $o['label'];
                        }
                    }
                    echo '<select class="form-select" name="' . e($field) . '"><option value="">— Select —</option>';
                    foreach ($options as $ov => $ol) {
                        echo '<option value="' . e($ov) . '"' . ((string)$value === (string)$ov ? ' selected' : '') . '>' . e($ol) . '</option>';
                    }
                    echo '</select>';
                } elseif ($type === 'textarea') {
                    echo '<textarea class="form-control" name="' . e($field) . '" rows="3">' . e($value) . '</textarea>';
                } elseif ($type === 'checkbox') {
                    echo '<div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="' . e($field) . '" value="1"' . ($value ? ' checked' : '') . '></div>';
                } elseif ($type === 'file') {
                    if ($value) {
                        echo '<div class="mb-2"><img src="' . e(asset((string)$value)) . '" alt="" class="img-thumbnail" style="max-height:90px"></div>';
                    }
                    echo '<input class="form-control" type="file" name="' . e($field) . '" accept="image/*">';
                } else {
                    $step = isset($f['step']) ? ' step="' . e($f['step']) . '"' : '';
                    echo '<input class="form-control" type="' . e($type) . '" name="' . e($field) . '" value="' . e($value) . '"' . $step . '>';
                }
                if (!empty($f['help'])) {
                    echo '<div class="form-text">' . e($f['help']) . '</div>';
                }
                echo '</div>';
            }
            echo '</div><div class="mt-4"><button class="btn btn-emerald" type="submit">'
                . '<i class="bi bi-check-lg"></i> Save ' . e($cfg['title']) . '</button></div></form></div></div>';
            return;
        }

        /* ------------------------------------------------------ List view */
        $q = trim((string)input('q', ''));
        $listWhere = $whereSql;
        $listParams = $params;
        if ($q !== '' && !empty($cfg['search'])) {
            $likes = [];
            foreach ($cfg['search'] as $sf) {
                $likes[] = "{$sf} LIKE ?";
                $listParams[] = '%' . $q . '%';
            }
            $listWhere .= ' AND (' . implode(' OR ', $likes) . ')';
        }
        $sort = (string)input('sort', '');
        $dir = strtolower((string)input('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $order = $cfg['order'] ?? 'id DESC';
        if ($sort !== '' && isset($cfg['columns'][$sort])) {
            $order = "{$sort} {$dir}";
        }
        $page = max(1, (int)input('page', 1));
        $perPage = 15;
        $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$listWhere}");
        $count->execute($listParams);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$listWhere} ORDER BY {$order} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
        $stmt->execute($listParams);
        $rows = $stmt->fetchAll();

        echo '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">'
            . '<h4 class="mb-0"><i class="bi ' . e($cfg['icon'] ?? 'bi-grid') . ' text-emerald"></i> ' . e($cfg['title']) . ' <span class="badge bg-light text-dark">' . $total . '</span></h4>'
            . '<div class="d-flex gap-2"><form class="d-flex" method="get"><input class="form-control form-control-sm" name="q" value="' . e($q) . '" placeholder="Search…">'
            . '<button class="btn btn-outline-secondary btn-sm ms-1" type="submit"><i class="bi bi-search"></i></button></form>'
            . '<a class="btn btn-emerald btn-sm" href="' . e($base) . '?action=new"><i class="bi bi-plus-lg"></i> Add New</a></div></div>';

        echo '<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr>';
        foreach ($cfg['columns'] as $field => $label) {
            $nextDir = ($sort === $field && $dir === 'ASC') ? 'desc' : 'asc';
            echo '<th><a class="text-decoration-none text-dark" href="' . e($base) . '?sort=' . e($field) . '&dir=' . $nextDir . '&q=' . e($q) . '">' . e($label) . '</a></th>';
        }
        echo '<th class="text-end">Actions</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="' . (count($cfg['columns']) + 1) . '" class="text-center text-muted py-4">No records found.</td></tr>';
        }
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($cfg['columns'] as $field => $label) {
                $val = $r[$field] ?? '';
                if (isset($cfg['display'][$field])) {
                    $val = ($cfg['display'][$field])($r);
                    echo '<td>' . $val . '</td>';
                } elseif (str_ends_with($field, '_at') || str_ends_with($field, '_date')) {
                    echo '<td>' . e(fmt_date($val)) . '</td>';
                } elseif (in_array($field, ['status', 'type'], true)) {
                    echo '<td>' . status_badge((string)$val) . '</td>';
                } else {
                    echo '<td>' . e($val) . '</td>';
                }
            }
            echo '<td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="' . e($base) . '?action=edit&id=' . (int)$r['id'] . '"><i class="bi bi-pencil"></i></a> '
                . '<form method="post" class="d-inline" data-confirm="Delete this record? This cannot be undone from the list view.">'
                . csrf_field() . '<input type="hidden" name="_op" value="delete">'
                . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                . '<button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button></form></td></tr>';
        }
        echo '</tbody></table></div></div>';

        if ($pages > 1) {
            echo '<nav class="mt-3"><ul class="pagination pagination-sm">';
            for ($p = 1; $p <= $pages; $p++) {
                echo '<li class="page-item' . ($p === $page ? ' active' : '') . '"><a class="page-link" href="' . e($base) . '?page=' . $p . '&q=' . e($q) . '&sort=' . e($sort) . '&dir=' . strtolower($dir) . '">' . $p . '</a></li>';
            }
            echo '</ul></nav>';
        }
    }
}
