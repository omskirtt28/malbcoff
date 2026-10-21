<?php
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../core/AccountChanges.php';

$intent = array_intersect_key($_POST, array_flip(['name','id','user_id','target_user_id']));
$intent['extra_fields'] = array_values(array_diff(array_keys($_POST), ['name','id','user_id','target_user_id','_csrf','client_key']));
run_account_change('account_name_update', $intent, static function (array $me): array {
    foreach (['id','user_id','target_user_id'] as $field) {
        if (isset($_POST[$field]) && filter_var($_POST[$field], FILTER_VALIDATE_INT) !== (int)$me['id']) {
            throw new RuntimeException('You can change only your own display name.');
        }
    }
    foreach (['email','role','branch_id','is_active','status','password','temporary_password','password_hash'] as $field) {
        if (array_key_exists($field, $_POST)) throw new RuntimeException('Only your display name can be changed here.');
    }
    $name = validate_display_name($_POST['name'] ?? '');
    Database::query('UPDATE users SET name=? WHERE id=? AND is_active=1', [$name,(int)$me['id']]);
    Security::audit('user.name_updated', 'user', (int)$me['id']);
    return ['ok'=>true, 'message'=>'Your display name has been saved.', 'name'=>$name];
}, false);
