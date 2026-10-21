<?php
require __DIR__ . '/../bootstrap.php';
require_once __DIR__.'/../core/InventoryDeletion.php';
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}

$transferId = filter_input(INPUT_GET, 'transfer_id', FILTER_VALIDATE_INT) ?: 0;
if ($transferId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid transfer.']);
    exit;
}

try {
    $row = Database::query(
        "SELECT t.*,sb.name source_branch,db.name destination_branch,
                " . snapshot_name_sql('inventory_transfers', 't', 'forwarded_by_snapshot', 'fu.name') . " forwarded_by_name," . snapshot_name_sql('inventory_transfers', 't', 'received_by_snapshot', 'ru.name') . " received_by_user_name,
                p.product_type,p.product_name,p.ram,p.storage,p.connectivity,p.color,
                br.name brand_name,pm.name model_name
         FROM inventory_transfers t
         JOIN branches sb ON sb.id=t.source_branch_id
         JOIN branches db ON db.id=t.destination_branch_id
         JOIN users fu ON fu.id=t.forwarded_by
         LEFT JOIN users ru ON ru.id=t.received_by
         JOIN products p ON p.id=t.product_id
         LEFT JOIN brands br ON br.id=p.brand_id
         LEFT JOIN product_models pm ON pm.id=p.model_id
         WHERE t.id=? LIMIT 1",
        [$transferId]
    )->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Transfer record not found.']);
        exit;
    }

    $branchId = Auth::branchId() ?: 0;
    if (!Auth::isOwner() && $branchId !== (int)$row['source_branch_id'] && $branchId !== (int)$row['destination_branch_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have access to this transfer.']);
        exit;
    }

    $units = [];
    if (($row['product_type'] ?? '') !== 'accessory') {
        $snapshotSelect = InventoryDeletion::snapshotsReady() ? ',itu.deleted_unit_id,itu.unit_snapshot' : ',NULL deleted_unit_id,NULL unit_snapshot';
        $identifierSelect = InventoryDeletion::ready() ? 'COALESCE(iu.imei,iu.archived_imei) imei,COALESCE(iu.imei2,iu.archived_imei2) imei2,COALESCE(iu.serial_no,iu.archived_serial_no) serial_no' : 'iu.imei,iu.imei2,iu.serial_no';
        $units = Database::query(
            "SELECT iu.id unit_id,{$identifierSelect},iu.condition_type,iu.condition_grade{$snapshotSelect}
             FROM inventory_transfer_units itu
             LEFT JOIN inventory_units iu ON iu.id=itu.unit_id
             WHERE itu.transfer_id=?
             ORDER BY itu.id",
            [$transferId]
        )->fetchAll();
        foreach ($units as &$unit) {
            $unit['unit_deleted'] = !empty($unit['deleted_unit_id']);
            if ($unit['unit_deleted']) {
                $snapshot = json_decode((string)$unit['unit_snapshot'], true) ?: [];
                foreach (['imei','imei2','serial_no','condition_type','condition_grade','product_label'] as $key) $unit[$key] = $snapshot[$key] ?? null;
            }
            unset($unit['unit_snapshot']);
        }
        unset($unit);
    }

    $name = ($row['product_type'] ?? '') === 'accessory'
        ? (string)$row['product_name']
        : trim((string)($row['brand_name'] ?? '') . ' ' . (string)($row['model_name'] ?? ''));
    $specs = [];
    foreach (['ram','storage','connectivity','color'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') $specs[] = $value;
    }

    $canReceive = !Auth::isOwner()
        && in_array((string)((Auth::user()['role'] ?? '')), ['branch_manager','inventory'], true)
        && $branchId === (int)$row['destination_branch_id']
        && ($row['status'] ?? '') === 'pending';

    echo json_encode([
        'transfer' => [
            'id' => (int)$row['id'],
            'reference_no' => $row['reference_no'],
            'status' => $row['status'],
            'source_branch' => $row['source_branch'],
            'destination_branch' => $row['destination_branch'],
            'quantity' => (int)$row['quantity'],
            'notes' => $row['notes'],
            'forwarded_by' => $row['forwarded_by_name'],
            'forwarded_at' => $row['forwarded_at'],
            'receiver_name' => $row['receiver_name'],
            'received_by_user' => $row['received_by_user_name'],
            'received_at' => $row['received_at'],
            'product_type' => $row['product_type'],
            'product_name' => $name !== '' ? $name : (string)$row['product_name'],
            'specs' => $specs ? implode(' • ', $specs) : '—',
            'can_receive' => $canReceive,
        ],
        'units' => $units,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load transfer details right now.']);
}
