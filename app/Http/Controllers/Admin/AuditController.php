<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audit jurnali (§10.7, faqat superadmin): kim, qachon, nimani o'zgartirdi.
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $type = $request->string('type')->toString();

        return Inertia::render('admin/audit', [
            'logs' => AuditLog::query()
                ->when($type !== '', fn ($q) => $q->where('subject_type', $type))
                ->with('user:id,name')
                ->latest('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'user' => $log->user?->name,
                    'action' => $log->action,
                    'subject_type' => $log->subject_type,
                    'subject_id' => $log->subject_id,
                    'subject_label' => $log->subject_label,
                    'changes' => $log->changes,
                    'ip' => $log->ip,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
            'types' => AuditLog::query()->distinct()->orderBy('subject_type')->pluck('subject_type'),
            'filters' => ['type' => $type],
        ]);
    }
}
