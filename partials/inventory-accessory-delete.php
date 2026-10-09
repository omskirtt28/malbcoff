<?php if ($canDeleteDeviceInventory): ?>
<div class="modal" id="deleteAccessoryInventoryModal" hidden>
    <div class="modal-backdrop" data-delete-accessory-close></div>
    <div class="modal-dialog inventory-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="deleteAccessoryInventoryTitle">
        <div class="modal-header">
            <div><span class="eyebrow delete-eyebrow">INVENTORY CONTROL</span><h2 id="deleteAccessoryInventoryTitle">Delete Accessory Inventory</h2><p class="modal-subtitle">Remove this branch's available stock so you can receive it again.</p></div>
            <button type="button" class="icon-button" data-delete-accessory-close aria-label="Close">×</button>
        </div>
        <form data-delete-accessory-form data-csrf="<?= e(Csrf::token()) ?>">
            <div class="modal-body inventory-delete-body">
                <div class="delete-summary-grid">
                    <div><span>Product</span><strong data-delete-accessory-product></strong></div>
                    <div><span>Branch</span><strong data-delete-accessory-branch></strong></div>
                    <div><span>Quantity to Delete</span><strong data-delete-accessory-quantity></strong></div>
                </div>
                <div class="delete-warning-panel"><div class="delete-warning-icon">!</div><div><strong>Delete all available stock in this row</strong><span>This permanently deletes this branch's inventory balance and sets available stock to zero. Product/barcode, other branches, sales and transfers remain recorded. A stock adjustment records the removed quantity.</span></div></div>
                <label class="field"><span>Reason <b>*</b></span><textarea name="reason" minlength="3" maxlength="180" required rows="3" placeholder="Example: Incorrect quantity received"></textarea></label>
                <label class="stock-confirm-acknowledgement delete-confirm-ack"><input type="checkbox" name="confirmed" required><span><strong>I confirm this product, branch and quantity.</strong><small>This removes the entire available quantity shown above. Use Adjust to remove only part of the quantity.</small></span></label>
                <div class="alert alert-error" data-delete-accessory-error role="alert" hidden></div>
            </div>
            <div class="modal-actions"><button class="btn btn-secondary" type="button" data-delete-accessory-close>Cancel</button><button class="btn btn-danger" type="submit">Delete Inventory</button></div>
        </form>
    </div>
</div>
<script src="assets/js/inventory-accessory-delete.js?v=<?= (int)filemtime(__DIR__.'/../assets/js/inventory-accessory-delete.js') ?>"></script>
<?php endif; ?>
