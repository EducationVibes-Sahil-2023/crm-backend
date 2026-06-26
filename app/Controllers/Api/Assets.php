<?php

namespace App\Controllers\Api;

use App\Models\AssetEventModel;
use App\Models\AssetModel;
use CodeIgniter\RESTful\ResourceController;

class Assets extends ResourceController
{
    protected $modelName = AssetModel::class;
    protected $format    = 'json';

    private AssetEventModel $events;

    public function __construct()
    {
        $this->events = new AssetEventModel();
    }

    /** GET /api/assets */
    public function index()
    {
        return $this->respond($this->model->orderBy('id', 'DESC')->findAll());
    }

    /** GET /api/assets/{id} — asset plus its activity timeline */
    public function show($id = null)
    {
        $asset = $this->model->find($id);
        if ($asset === null) {
            return $this->failNotFound("Asset #{$id} not found");
        }

        return $this->respond($this->present((int) $id));
    }

    /** POST /api/assets — admin creates and assigns an asset (status: pending) */
    public function create()
    {
        $input = $this->request->getJSON(true) ?? $this->request->getPost();
        $data  = $this->pickEditable($input);
        $data['status'] = 'pending';
        $this->computeWarranty($data, $data);

        // Asset tag is auto-generated — ignore any client-supplied value.
        $autoTag = trim((string) ($data['tag'] ?? '')) === '';
        if ($autoTag) {
            unset($data['tag']);
        }

        if (! $this->model->insert($data)) {
            return $this->failValidationErrors($this->model->errors());
        }

        $id   = (int) $this->model->getInsertID();

        if ($autoTag) {
            $this->model->update($id, ['tag' => sprintf('AST-%04d', $id)]);
        }
        $by   = $this->actor($input);
        $owner = $data['owner_name'] ?? 'the assignee';
        $this->events->log($id, 'created', $by['actor'], $by['role'], "Asset created and assigned to {$owner}.");

        return $this->respondCreated($this->present($id));
    }

    /** PUT /api/assets/{id} — user fills / corrects info (blocked once locked) */
    public function update($id = null)
    {
        $asset = $this->model->find($id);
        if ($asset === null) {
            return $this->failNotFound("Asset #{$id} not found");
        }

        if (! in_array($asset['status'], AssetModel::EDITABLE_STATUSES, true)) {
            return $this->fail(
                "This asset is {$asset['status']} and locked for editing.",
                409,
            );
        }

        $input   = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $data    = $this->pickEditable($input);
        $changed = $this->changedFields($asset, $data);
        $this->computeWarranty(array_merge($asset, $data), $data);

        if ($data === [] || ! $this->model->update($id, $data)) {
            return $this->failValidationErrors($this->model->errors() ?: ['update' => 'Nothing to update']);
        }

        $by = $this->actor($input);
        if ($changed !== []) {
            $this->events->log((int) $id, 'updated', $by['actor'], $by['role'], 'Updated ' . implode(', ', $changed) . '.');
        }

        return $this->respond($this->present((int) $id));
    }

    /** DELETE /api/assets/{id} */
    public function delete($id = null)
    {
        if ($this->model->find($id) === null) {
            return $this->failNotFound("Asset #{$id} not found");
        }
        $this->model->delete($id);
        $this->events->where('asset_id', $id)->delete();

        return $this->respondDeleted(['id' => $id]);
    }

    /** POST /api/assets/{id}/submit — user sends for verification */
    public function submit($id = null)
    {
        return $this->transition($id, ['pending', 'rejected'], 'submitted', 'submitted', function ($asset, $by) {
            return [
                'patch' => ['status' => 'submitted', 'reject_reason' => null],
                'message' => 'Submitted for verification.',
            ];
        });
    }

    /** POST /api/assets/{id}/verify — admin approves and locks */
    public function verify($id = null)
    {
        return $this->transition($id, ['submitted'], 'verified', 'verified', function ($asset, $by) {
            return [
                'patch' => [
                    'status'      => 'verified',
                    'verified_by' => $by['actor'],
                    'verified_at' => date('Y-m-d H:i:s'),
                    'reject_reason' => null,
                ],
                'message' => 'Verified and locked. Information can no longer be changed by the user.',
            ];
        });
    }

    /** POST /api/assets/{id}/reject — admin sends back with a reason */
    public function reject($id = null)
    {
        $input  = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            return $this->failValidationErrors(['reason' => 'A rejection reason is required.']);
        }

        return $this->transition($id, ['submitted'], 'rejected', 'rejected', function ($asset, $by) use ($reason) {
            return [
                'patch' => ['status' => 'rejected', 'reject_reason' => $reason],
                'message' => "Rejected: {$reason}",
            ];
        });
    }

    /** POST /api/assets/{id}/reopen — admin unlocks a verified/rejected asset */
    public function reopen($id = null)
    {
        return $this->transition($id, ['verified', 'rejected'], 'pending', 'reopened', function ($asset, $by) {
            return [
                'patch' => ['status' => 'pending', 'verified_by' => null, 'verified_at' => null, 'reject_reason' => null],
                'message' => 'Re-opened for editing.',
            ];
        });
    }

    /** POST /api/assets/{id}/comments — add a comment to the timeline */
    public function comment($id = null)
    {
        $asset = $this->model->find($id);
        if ($asset === null) {
            return $this->failNotFound("Asset #{$id} not found");
        }
        $input   = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            return $this->failValidationErrors(['message' => 'Comment cannot be empty.']);
        }
        $by = $this->actor($input);
        $this->events->log((int) $id, 'comment', $by['actor'], $by['role'], $message);

        return $this->respondCreated($this->present((int) $id));
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * Shared status-transition runner.
     *
     * @param callable(array,array):array $build returns ['patch'=>[], 'message'=>string]
     */
    private function transition($id, array $from, string $to, string $eventType, callable $build)
    {
        $asset = $this->model->find($id);
        if ($asset === null) {
            return $this->failNotFound("Asset #{$id} not found");
        }
        if (! in_array($asset['status'], $from, true)) {
            return $this->fail("Cannot move asset from '{$asset['status']}' to '{$to}'.", 409);
        }

        $input = $this->request->getJSON(true) ?? $this->request->getRawInput();
        $by    = $this->actor($input);
        $spec  = $build($asset, $by);

        $this->model->update($id, $spec['patch']);
        $this->events->log((int) $id, $eventType, $by['actor'], $by['role'], $spec['message']);

        return $this->respond($this->present((int) $id));
    }

    /** Asset row merged with its timeline. */
    private function present(int $id): array
    {
        $asset = $this->model->find($id);
        $asset['events'] = $this->events->forAsset($id);

        return $asset;
    }

    /** Keep only editable fields from arbitrary input. */
    private function pickEditable(array $input): array
    {
        $out = [];
        foreach (AssetModel::EDITABLE_FIELDS as $f) {
            if (array_key_exists($f, $input)) {
                $out[$f] = $input[$f] === '' ? null : $input[$f];
            }
        }

        return $out;
    }

    /** Human-readable list of fields whose value actually changed. */
    private function changedFields(array $asset, array $data): array
    {
        $changed = [];
        foreach ($data as $k => $v) {
            if ((string) ($asset[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[] = str_replace('_', ' ', $k);
            }
        }

        return $changed;
    }

    /**
     * Derive warranty_expiry from purchase_date + warranty_years when possible.
     *
     * @param array $source values to read (e.g. existing row merged with edits)
     * @param array $dest   array the computed expiry is written into (by ref)
     */
    private function computeWarranty(array $source, array &$dest): void
    {
        $date  = $source['purchase_date'] ?? null;
        $years = (float) ($source['warranty_years'] ?? 0);
        if ($date && $years > 0) {
            $months                  = (int) round($years * 12);
            $dest['warranty_expiry'] = date('Y-m-d', strtotime("{$date} +{$months} months"));
        }
    }

    /** Resolve the acting user/role from request body (View-as switcher). */
    private function actor(array $input): array
    {
        $role = ($input['role'] ?? '') === 'admin' ? 'admin' : 'user';

        return [
            'actor' => trim((string) ($input['actor'] ?? '')) ?: ucfirst($role),
            'role'  => $role,
        ];
    }
}
