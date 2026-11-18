<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CommissionSetting;
use App\Models\Setting;
use App\Services\ServiceGroupFlush;
use App\Services\ServiceGroupStaging;
use App\Services\SystemRunRules;
use Illuminate\Http\Request;

/**
 * ⚠️ THE SYSTEM RUN RULES, EDITABLE BY THE OWNER.
 *
 * Asked for twice, the second time after a rule was flipped, shipped, and
 * rejected the same day:
 *
 *   "Mta system run ekat adala file ekak hadl denn, edit kragnn puluwn. Naththn
 *    meka aye aye wardinwa. Dn me hadanne kiweni parada? Ekak hadankot anith eka
 *    wardinwa. Common ekak thiyala, system run ekat adalawa, eken check krala run
 *    eka yawanm hari ne."
 *   = give me a file for the System Run I can edit myself, or this keeps going
 *     wrong — how many times is this now? Fixing one breaks the other. Put a
 *     common one in place, check the run against it, and run from that.
 *
 * They are right. Every rule here had been a line of PHP, so changing a business
 * decision meant a code change, a test rewrite and a deploy — and the rule that
 * kept flipping (does the main settle its rollup?) was flipped five times in
 * eight days, breaking a different set of accounts each way.
 *
 * So the rules are now DATA. SystemRunRules::editable() is the contract; this
 * screen renders and saves whatever is in it, and shows what the CURRENT
 * network would do under the saved values before anything is run.
 *
 * SUPER-ADMIN ONLY: these decide who is paid and what is destroyed.
 */
class SystemRunRulesController extends Controller
{
    public function index(ServiceGroupFlush $flush)
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403, 'Only a super admin can see the System Run rules.');

        $rate = (float) CommissionSetting::current()->commission_per_student;

        // What each stage asks for, under the values saved right now.
        $ladder = [];

        foreach ([0, 1, 2] as $stage) {
            $step = SystemRunRules::stepFor($stage);

            $ladder[] = [
                'label' => $step.'-to-'.$step,
                'each_side' => $step * $rate,
                'releases' => $step * $rate * 2,
                'repeating' => SystemRunRules::isRepeating($stage),
            ];
        }

        // ⚠️ A DRY RUN, NOT A RUN. preview() writes nothing — it is the same
        // simulation the System Run screen shows — so the owner can change a
        // rule, look at the effect on their real accounts, and only then run.
        $preview = $flush->preview(ServiceGroupFlush::latestSelectableCutoff());

        return view('dashboard.system-run.rules', [
            'rules' => SystemRunRules::editable(),
            'values' => SystemRunRules::current(),
            'ladder' => $ladder,
            'rate' => $rate,
            'preview' => $preview,
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403, 'Only a super admin can change the System Run rules.');

        $changed = [];

        foreach (SystemRunRules::editable() as $rule) {
            $key = $rule['key'];

            if ($rule['type'] === 'boolean') {
                $new = $request->boolean($key);
            } else {
                // Clamped at 1: a zero or negative step would make an account
                // "balanced" at nothing and release on every run forever.
                $new = max(1, (int) $request->input($key, $rule['default']));
            }

            $old = Setting::get($key, $rule['default']);

            if ((string) $old !== (string) $new) {
                $changed[] = $rule['label'];
            }

            Setting::set($key, $new, $rule['type'], 'commission');
        }

        // The staged config is cached separately from the settings themselves.
        ServiceGroupStaging::forget();

        if ($changed === []) {
            return back()->with('success', 'Saved — no rule changed.');
        }

        return back()->with('success', 'System Run rules saved. Changed: '.implode('; ', $changed).'.');
    }
}
