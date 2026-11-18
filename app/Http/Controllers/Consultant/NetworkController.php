<?php

namespace App\Http\Controllers\Consultant;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Consultant;
use App\Models\Group;
use App\Models\GroupMember;

/**
 * "My Network" — the consultant tree below the signed-in consultant.
 *
 * A consultant sponsors students; when a student is promoted to consultant they
 * become a branch, and everything their branch registers keeps rolling income
 * up. Nothing above the viewer is ever shown — the tree only goes downward.
 */
class NetworkController extends Controller
{
    protected function consultant(): Consultant
    {
        $consultant = auth()->user()->consultant;
        abort_if(! $consultant, 403, 'No consultant profile found for your account.');

        return $consultant;
    }

    public function index()
    {
        $me = $this->consultant();

        $ids = array_merge([$me->id], $me->downlineIds());

        $nodes = Consultant::whereIn('id', $ids)
            ->with(['user:id,name,email', 'owner.user:id,name'])
            ->orderBy('id')
            ->get();

        // Placement accounts belong to their owner, so income and students
        // recorded against SCN-00166 must be counted for the consultant that
        // owns it — and a child sponsored "by" that account is really a child
        // of the owner.
        $hidden = Consultant::whereIn('owner_consultant_id', $ids)
            ->where('is_hidden', true)
            ->get(['id', 'owner_consultant_id']);

        $ownerOfAccount = $hidden->pluck('owner_consultant_id', 'id');
        $accountIdsFor = fn (int $id) => array_merge(
            [$id],
            $hidden->where('owner_consultant_id', $id)->pluck('id')->all(),
        );

        $allAccountIds = array_merge($ids, $hidden->pluck('id')->all());

        // Cancelled excluded (2026-09-06): this is a consultant-facing earnings
        // figure per account, and it had no status filter — so a removed or
        // forfeited amount kept showing as earned on the network page.
        $earnedByAccount = Commission::whereIn('consultant_id', $allAccountIds)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('consultant_id, SUM(amount) as total')
            ->groupBy('consultant_id')
            ->pluck('total', 'consultant_id');

        $studentsByAccount = GroupMember::whereIn('group_id', Group::whereIn('consultant_id', $allAccountIds)->select('id'))
            ->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->selectRaw('groups.consultant_id as consultant_id, COUNT(*) as total')
            ->groupBy('groups.consultant_id')
            ->pluck('total', 'consultant_id');

        $sumOver = fn (array $accountIds, $bag) => (float) collect($accountIds)->sum(fn ($id) => (float) ($bag[$id] ?? 0));

        // parent id -> children, with a hidden sponsor resolved to its owner.
        $childrenOf = $nodes->groupBy(function (Consultant $c) use ($ownerOfAccount) {
            $parent = $c->parent_consultant_id;

            return (int) ($ownerOfAccount[$parent] ?? $parent);
        });

        $tree = $this->branch($me->id, $nodes->keyBy('id'), $childrenOf, $accountIdsFor, $earnedByAccount, $studentsByAccount, $sumOver, 0);

        $stats = [
            'downline' => count($ids) - 1,
            'direct' => ($childrenOf[$me->id] ?? collect())->count(),
            'levels' => $tree ? $this->depth($tree) : 0,
        ];

        return view('consultant.network.index', compact('tree', 'stats', 'me'));
    }

    /**
     * Flatten the tree into display rows, depth-first, so the view can render it
     * as an indented list without recursing in Blade.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function branch(int $id, $byId, $childrenOf, callable $accountIdsFor, $earned, $students, callable $sumOver, int $level): array
    {
        $node = $byId[$id] ?? null;

        if (! $node) {
            return [];
        }

        $accountIds = $accountIdsFor($id);

        $rows = [[
            'id' => $node->id,
            'level' => $level,
            'code' => $node->scn_code ?? $node->consultant_code,
            'name' => $node->display_name,
            'email' => $node->user?->email,
            'accounts' => count($accountIds),
            'students' => (int) $sumOver($accountIds, $students),
            'earned' => $sumOver($accountIds, $earned),
            'children' => ($childrenOf[$id] ?? collect())->count(),
        ]];

        foreach ($childrenOf[$id] ?? [] as $child) {
            $rows = array_merge($rows, $this->branch($child->id, $byId, $childrenOf, $accountIdsFor, $earned, $students, $sumOver, $level + 1));
        }

        return $rows;
    }

    /** Deepest level present in the flattened tree, counting the viewer as 0. */
    protected function depth(array $tree): int
    {
        return max(array_column($tree, 'level'));
    }
}
