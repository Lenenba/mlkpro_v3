<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;

class WorkProofController extends Controller
{
    public function show(Work $work)
    {
        $this->authorize('view', $work);

        $work->load('customer:id,company_name,first_name,last_name,email');

        $tasks = $work->tasks()
            ->with(['assignee.user:id,name', 'media.user:id,name', 'materials.product:id,name,unit,price'])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get([
                'id',
                'title',
                'status',
                'due_date',
                'start_time',
                'end_time',
                'assigned_team_member_id',
            ])
            ->map(function (Task $task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'due_date' => $task->due_date,
                    'start_time' => $task->start_time,
                    'end_time' => $task->end_time,
                    'assignee' => $task->assignee?->user?->name,
                    'materials' => $task->materials
                        ->sortBy('sort_order')
                        ->values()
                        ->map(function ($material) {
                            return [
                                'id' => $material->id,
                                'label' => $material->label,
                                'quantity' => $material->quantity,
                                'unit' => $material->unit,
                                'unit_price' => $material->unit_price,
                                'billable' => $material->billable,
                                'product_name' => $material->product?->name,
                            ];
                        }),
                    'media' => $task->media
                        ->sortByDesc('created_at')
                        ->values()
                        ->map(function ($media) {
                            $path = $media->path;
                            $url = $path
                                ? (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                                    ? $path
                                    : Storage::disk('public')->url($path))
                                : null;
                            $source = $media->meta['source'] ?? null;
                            $uploadedBy = $source === 'client-public' ? null : $media->user?->name;

                            return [
                                'id' => $media->id,
                                'type' => $media->type,
                                'media_type' => $media->media_type,
                                'url' => $url,
                                'note' => $media->meta['note'] ?? null,
                                'source' => $source,
                                'uploaded_by' => $uploadedBy,
                                'uploaded_at' => $media->created_at,
                            ];
                        }),
                ];
            });

        return $this->inertiaOrJson('Work/Proofs', [
            'viewer' => 'team',
            'work' => [
                'id' => $work->id,
                'number' => $work->number,
                'job_title' => $work->job_title,
                'status' => $work->status,
                'start_date' => $work->start_date,
                'end_date' => $work->end_date,
            ],
            'customer' => $work->customer ? [
                'id' => $work->customer->id,
                'company_name' => $work->customer->company_name,
                'first_name' => $work->customer->first_name,
                'last_name' => $work->customer->last_name,
                'email' => $work->customer->email,
            ] : null,
            'tasks' => $tasks,
        ]);
    }
}
