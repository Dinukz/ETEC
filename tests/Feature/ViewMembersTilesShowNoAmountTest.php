<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ⚠️ 2026-10-02: "View members for eke methana me penwana SCN group wala LKR
 * amount eka hide krla denn methana withri krnn one."
 *
 * The account tiles under "View members for" are a switch for which account's
 * students to list — they no longer print what each account earned. Only
 * there: the Service Groups table above keeps every figure.
 */
class ViewMembersTilesShowNoAmountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_tiles_show_no_amount(): void
    {
        $this->seed();
        \App\Services\FinanceSchemaRepair::ensure();
        Cache::forever('settings.mail.grouped', collect());

        $html = $this->actingAs(User::where('email', 'consultant@etec.lk')->firstOrFail())
            ->get(route('consultant.groups.index'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'View members for');
        $this->assertNotFalse($start, 'the picker is on the page');

        // The tiles sit between the heading and the network search below them.
        $end = strpos($html, 'search anyone below you', $start);
        $tiles = substr($html, $start, ($end === false ? strlen($html) : $end) - $start);

        $this->assertStringContainsString('Main account', $tiles, 'the tiles are still there');
        $this->assertStringNotContainsString('LKR', $tiles);
        $this->assertStringNotContainsString('earned to date', $tiles);
    }
}
