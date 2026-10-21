<?php
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/AccountChanges.php';

run_account_change('admin_branch_rename', array_intersect_key($_POST, array_flip(['branch_id','name'])), static function (array $actor): array {
    $id = filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $name = preg_replace('/\s+/u', ' ', trim((string)($_POST['name'] ?? ''))) ?? '';
    $limit = (int)Database::query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branches' AND COLUMN_NAME='name'")->fetchColumn();
    if ($name === '') throw new RuntimeException('Branch name is required.');
    if ($limit < 1 || mb_strlen($name) > $limit) throw new RuntimeException('Branch name is too long. Use up to ' . $limit . ' characters.');
    if (preg_match('/[<>\x00-\x1F\x7F]/u', $name)) throw new RuntimeException('Branch name cannot contain HTML or special control characters.');
    // Serialize renames in ID order: branches has no unique name index.
    $branches = Database::query('SELECT id,name FROM branches ORDER BY id FOR UPDATE')->fetchAll();
    $target = null;
    foreach ($branches as $branch) if ((int)$branch['id'] === $id) $target = $branch;
    if (!$target) throw new RuntimeException('Branch not found.');
    if (Database::query('SELECT id FROM branches WHERE name=? AND id<>? LIMIT 1', [$name,$id])->fetchColumn()) {
        throw new RuntimeException('That branch name is already in use.');
    }
    Database::query('UPDATE branches SET name=? WHERE id=?', [$name,$id]);
    Security::audit('branch.renamed', 'branch', $id, ['old_name'=>$target['name'], 'new_name'=>$name]);
    return ['ok'=>true, 'message'=>'Branch name saved.'];
});
