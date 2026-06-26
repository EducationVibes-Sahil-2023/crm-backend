<?php

namespace App\Controllers\Api;

use App\Models\AssetEventModel;
use App\Models\AssetModel;
use App\Models\InventoryAssignmentModel;
use App\Models\InventoryItemModel;
use App\Models\InventoryMovementModel;
use CodeIgniter\RESTful\ResourceController;

class Inventory extends ResourceController
{
    protected $modelName = InventoryItemModel::class;
    protected $format    = 'json';

    private InventoryMovementModel $movements;
    private InventoryAssignmentModel $assignments;

    public function __construct()
    {
        $this->movements   = new InventoryMovementModel();
        $this->assignments = new InventoryAssignmentModel();
    }

    /** GET /api/inventory — each item annotated with outstanding assigned units */
    public function index()
    {
        $items = $this->model->orderBy('id', 'DESC')->findAll();
        $out   = $this->assignments->outstandingByItem();
        foreach ($items as &$item) {
            $item['assigned'] = $out[(int) $item['id']] ?? 0;
        }

        return $this->respond($items);
    }

    /** GET /api/inventory/{id} — item plus movement history + assignments */
    public function show($id = null)
    {
        $item = $this->model->find($id);
        if ($item === null) {
            return $this->failNotFound("Item #{$id} not found");
        }

        return $this->respond($this->withDetail((int) $id));
    }

    /** POST /api/inventory */
    public function create()
    {
        $input = $this->request->getJSON(true) ?? $this->request->getPost();
        $data  = $this->pick($input);

        if (! $this->model->insert($data)) {
            return $this->failValidationErrors($this->model->errors());
        }

        $id  = (int) $this->model->getInsertID();
        $qty = (int) ($data['quantity'] ?? 0);
        if ($qty > 0) {
            $this->movements->insert([
                'item_id' => $id, 'type' => 'in', 'qty' => $qty, 'balance_after' => $qty,
                'reason' => 'Initial stock', 'actor' => $this->actor($input),
            ]);
        }

        return $this->respondCreated($this->withDetail($id));
    }

    /** PUT /api/inventory/{id} — edit item details (quantity handled via /adjust) */
    public function update($id = null)
    {
        if ($this->model->find($id) === null) {
            return $this->failNotFound("Item #{$id} not found");
        }
        $input = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $data  = $this->pick($input);
        unset($data['quantity']); // stock changes only through /adjust

        if ($data === [] || ! $this->model->update($id, $data)) {
            return $this->failValidationErrors($this->model->errors() ?: ['update' => 'Nothing to update']);
        }

        return $this->respond($this->withDetail((int) $id));
    }

    /** DELETE /api/inventory/{id} */
    public function delete($id = null)
    {
        if ($this->model->find($id) === null) {
            return $this->failNotFound("Item #{$id} not found");
        }
        $this->model->delete($id);
        $this->movements->where('item_id', $id)->delete();

        return $this->respondDeleted(['id' => $id]);
    }

    /** POST /api/inventory/{id}/adjust  { type: in|out, qty, reason, actor } */
    public function adjust($id = null)
    {
        $item = $this->model->find($id);
        if ($item === null) {
            return $this->failNotFound("Item #{$id} not found");
        }
        $input = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $type  = ($input['type'] ?? '') === 'out' ? 'out' : 'in';
        $qty   = (int) ($input['qty'] ?? 0);
        if ($qty <= 0) {
            return $this->failValidationErrors(['qty' => 'Quantity must be greater than zero.']);
        }

        $current = (int) $item['quantity'];
        $balance = $type === 'in' ? $current + $qty : $current - $qty;
        if ($balance < 0) {
            return $this->fail("Cannot remove {$qty}; only {$current} in stock.", 409);
        }

        $this->model->update($id, ['quantity' => $balance]);
        $this->movements->insert([
            'item_id'       => (int) $id,
            'type'          => $type,
            'qty'           => $qty,
            'balance_after' => $balance,
            'reason'        => trim((string) ($input['reason'] ?? '')) ?: null,
            'actor'         => $this->actor($input),
        ]);

        return $this->respond($this->withDetail((int) $id));
    }

    /**
     * POST /api/inventory/{id}/assign
     * { assignee_name, assignee_email?, qty, note?, actor?, create_asset? }
     * Issues units to a user (reduces stock) and optionally creates an asset record.
     */
    public function assign($id = null)
    {
        $item = $this->model->find($id);
        if ($item === null) {
            return $this->failNotFound("Item #{$id} not found");
        }
        $input = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $name  = trim((string) ($input['assignee_name'] ?? ''));
        $qty   = (int) ($input['qty'] ?? 0);
        if ($name === '') {
            return $this->failValidationErrors(['assignee_name' => 'Assignee name is required.']);
        }
        if ($qty <= 0) {
            return $this->failValidationErrors(['qty' => 'Quantity must be greater than zero.']);
        }

        $available = (int) $item['quantity'];
        if ($qty > $available) {
            return $this->fail("Cannot assign {$qty}; only {$available} available.", 409);
        }

        $email   = trim((string) ($input['assignee_email'] ?? '')) ?: null;
        $note    = trim((string) ($input['note'] ?? '')) ?: null;
        $actor   = $this->actor($input);
        $balance = $available - $qty;

        // Reduce stock + log movement.
        $this->model->update($id, ['quantity' => $balance]);
        $this->movements->insert([
            'item_id'       => (int) $id,
            'type'          => 'out',
            'qty'           => $qty,
            'balance_after' => $balance,
            'reason'        => "Assigned to {$name}" . ($note ? " ({$note})" : ''),
            'actor'         => $actor,
        ]);

        // Optionally create a linked asset record for the user.
        $assetId = null;
        if (! empty($input['create_asset'])) {
            $assetId = $this->createLinkedAsset($item, $name, $email, $qty, $actor);
        }

        $this->assignments->insert([
            'item_id'        => (int) $id,
            'assignee_name'  => $name,
            'assignee_email' => $email,
            'qty'            => $qty,
            'qty_returned'   => 0,
            'status'         => 'issued',
            'note'           => $note,
            'asset_id'       => $assetId,
            'issued_by'      => $actor,
            'issued_at'      => date('Y-m-d H:i:s'),
        ]);

        return $this->respondCreated($this->withDetail((int) $id));
    }

    /**
     * POST /api/inventory/assignments/{aid}/return
     * { qty?, actor? } — returns units back to stock (defaults to all outstanding).
     */
    public function returnUnits($aid = null)
    {
        $a = $this->assignments->find($aid);
        if ($a === null) {
            return $this->failNotFound("Assignment #{$aid} not found");
        }
        $outstanding = (int) $a['qty'] - (int) $a['qty_returned'];
        if ($outstanding <= 0) {
            return $this->fail('Everything has already been returned.', 409);
        }

        $input = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $qty   = isset($input['qty']) ? (int) $input['qty'] : $outstanding;
        if ($qty <= 0 || $qty > $outstanding) {
            return $this->failValidationErrors(['qty' => "Enter between 1 and {$outstanding}."]);
        }

        $itemId = (int) $a['item_id'];
        $item   = $this->model->find($itemId);
        $balance = (int) $item['quantity'] + $qty;

        $this->model->update($itemId, ['quantity' => $balance]);
        $this->movements->insert([
            'item_id'       => $itemId,
            'type'          => 'in',
            'qty'           => $qty,
            'balance_after' => $balance,
            'reason'        => "Returned by {$a['assignee_name']}",
            'actor'         => $this->actor($input),
        ]);

        $returned = (int) $a['qty_returned'] + $qty;
        $full     = $returned >= (int) $a['qty'];
        $this->assignments->update($aid, [
            'qty_returned' => $returned,
            'status'       => $full ? 'returned' : 'partial',
            'returned_at'  => $full ? date('Y-m-d H:i:s') : null,
        ]);

        return $this->respond($this->withDetail($itemId));
    }

    // ---- helpers ----

    private function createLinkedAsset(array $item, string $name, ?string $email, int $qty, string $actor): ?int
    {
        $assets = new AssetModel();
        $data   = [
            'name'        => $item['name'] . ($qty > 1 ? " (x{$qty})" : ''),
            'category'    => $item['category'] ?? null,
            'image_url'   => $item['image_url'] ?? null,
            'description' => "Issued from inventory item #{$item['id']} ({$item['sku']}). Quantity: {$qty}.",
            'owner_name'  => $name,
            'owner_email' => $email,
            'status'      => 'pending',
        ];
        if (! $assets->insert($data)) {
            return null;
        }
        $assetId = (int) $assets->getInsertID();
        $assets->update($assetId, ['tag' => sprintf('AST-%04d', $assetId)]);
        (new AssetEventModel())->log($assetId, 'created', $actor, 'admin', "Created from inventory assignment to {$name}.");

        return $assetId;
    }

    private function withDetail(int $id): array
    {
        $item = $this->model->find($id);
        $item['movements']   = $this->movements->forItem($id);
        $item['assignments'] = $this->assignments->forItem($id);
        $item['assigned']    = array_sum(array_map(
            static fn ($a) => (int) $a['qty'] - (int) $a['qty_returned'],
            $item['assignments'],
        ));

        return $item;
    }

    private function pick(array $input): array
    {
        $out = [];
        foreach (InventoryItemModel::FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $out[$f] = $input[$f] === '' ? null : $input[$f];
            }
        }

        return $out;
    }

    private function actor(array $input): string
    {
        return trim((string) ($input['actor'] ?? '')) ?: 'System';
    }
}
