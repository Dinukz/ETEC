<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⚠️ THE PROFILE PHOTO: uploading it, and everyone else being able to see it.
 *
 * Reported 2026-09-02: "meke profile photo eka dann ba eka fix krann, anith eka
 * meke profile photo eka anith account walatath dakinn puluwn wenn one — example
 * gaththoth adminta penwann one."
 *
 * TWO SEPARATE FAULTS, and the first one hid the second.
 *
 * 1. THE UPLOAD SILENTLY DISCARDED THE FILE. The modal sent
 *    `fetch(method: 'PATCH')` with a FormData body. PHP only parses a
 *    multipart/form-data body on a real POST — on PATCH/PUT it fills neither
 *    $_FILES nor $_POST — so `$request->file('avatar')` was null and validation
 *    answered "The avatar field is required" underneath a preview of the very
 *    image being rejected. (The preview is drawn client-side by FileReader,
 *    which is why it looked fine.) Fixed with Laravel method spoofing: POST the
 *    form with `_method=PATCH`.
 *
 * 2. NINE SCREENS DREW THEIR OWN INITIAL CIRCLE, so even a photo that HAD
 *    uploaded appeared nowhere but the profile page. They now share
 *    <x-user-avatar>.
 */
class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    private function consultant(): User
    {
        return User::where('email', 'consultant@etec.lk')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@etec.lk')->firstOrFail();
    }

    // ------------------------------------------------------------------------

    /**
     * ⚠️ THE FIX, EXACTLY AS THE BROWSER SENDS IT: a POST carrying _method.
     *
     * Laravel's test client fills $_FILES itself whatever verb is used, so a
     * `$this->patch(...)` here would pass even with the bug fully present — it
     * cannot reproduce PHP's multipart parsing. Posting with the spoof is what
     * the page actually does, so this is what is asserted.
     */
    public function test_the_photo_uploads_the_way_the_page_sends_it(): void
    {
        $user = $this->consultant();

        $response = $this->actingAs($user)->post(route('profile.avatar'), [
            '_method' => 'PATCH',
            'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400),
        ]);

        $response->assertOk();

        $path = $user->fresh()->avatar;

        $this->assertNotNull($path, 'the file must actually be stored, not just accepted');
        $this->assertStringStartsWith('avatars/', $path, 'stored where MediaController is allowed to serve from');
        Storage::disk('public')->assertExists($path);

        $response->assertJson(['avatar_url' => route('media.show', $path)]);
    }

    /** A non-image, or nothing at all, is still refused. */
    public function test_a_non_image_is_still_rejected(): void
    {
        $this->actingAs($this->consultant())
            ->post(route('profile.avatar'), [
                '_method' => 'PATCH',
                'avatar' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($this->consultant()->fresh()->avatar);
    }

    /**
     * ⚠️ THE SECOND HALF OF THE REPORT — the admin must SEE it.
     *
     * User Management drew its own initial circle, so an uploaded photo changed
     * nothing anywhere outside the owner's own profile page.
     */
    public function test_an_admin_sees_the_photo_in_user_management(): void
    {
        $user = $this->consultant();

        $this->actingAs($user)->post(route('profile.avatar'), [
            '_method' => 'PATCH',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $url = route('media.show', $user->fresh()->avatar);

        $this->actingAs($this->admin())
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee($url, false);
    }

    /** ...and on the Edit User page, which is where an admin acts on a person. */
    public function test_an_admin_sees_the_photo_on_the_edit_user_page(): void
    {
        $user = $this->consultant();

        $this->actingAs($user)->post(route('profile.avatar'), [
            '_method' => 'PATCH',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $this->actingAs($this->admin())
            ->get(route('users.edit', $user))
            ->assertOk()
            ->assertSee(route('media.show', $user->fresh()->avatar), false);
    }

    /**
     * ⚠️ THE WHOLE UPLOAD SCRIPT MUST FIT INSIDE ITS x-data ATTRIBUTE (2026-09-02).
     *
     * The avatar modal lives in an Alpine `x-data="{ ... }"` attribute. A
     * comment was written inside it containing double quotes and a `->`. The
     * quote ended the attribute, the `>` then ended the whole <div> tag, and
     * everything after spilled out as a column of visible words down the middle
     * of the profile page.
     *
     * ⚠️ THE OBVIOUS TEST FOR THIS DOES NOT WORK. assertDontSeeText() filters
     * through PHP's strip_tags(), which is far more forgiving than a browser's
     * tokenizer: with the fault deliberately reintroduced, the page still
     * "saw" nothing wrong and the assertion passed. Verified, not assumed —
     * a guard that cannot fail is worse than none, because it is believed.
     *
     * So this models the actual HTML rule instead: an attribute value ends at
     * the next double quote, full stop. Slice the response from `x-data="` to
     * that quote and require the whole script to still be inside it. Any
     * character that truncates it — a quote, and by extension the `>` that
     * follows — leaves the slice short and fails here.
     */
    public function test_the_upload_script_stays_inside_its_attribute(): void
    {
        $html = $this->actingAs($this->consultant())
            ->get(route('profile.edit'))
            ->assertOk()
            ->getContent();

        // The avatar block is the x-data carrying currentUrl; find it, then walk
        // back to the attribute that opened it.
        $anchor = strpos($html, 'currentUrl:');
        $this->assertNotFalse($anchor, 'guard: the avatar block is still on the page');

        $open = strrpos(substr($html, 0, $anchor), 'x-data="');
        $this->assertNotFalse($open, 'guard: it is still an x-data attribute');

        $valueStart = $open + strlen('x-data="');
        $valueEnd = strpos($html, '"', $valueStart);

        $attribute = substr($html, $valueStart, $valueEnd - $valueStart);

        foreach (["form.append('_method', 'PATCH')", 'FileReader', 'X-CSRF-TOKEN'] as $mustBeInside) {
            $this->assertStringContainsString(
                $mustBeInside,
                $attribute,
                'the Alpine block is cut short — something inside x-data closed the attribute, '.
                'and everything after it renders as text on the page',
            );
        }
    }

    /** Someone with no photo still gets their initial — the fallback must hold. */
    public function test_a_user_without_a_photo_still_shows_their_initial(): void
    {
        $this->assertNull($this->consultant()->avatar, 'guard: no photo uploaded');

        $this->actingAs($this->admin())
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee(strtoupper(substr($this->consultant()->name, 0, 1)));
    }

    /**
     * ⚠️ The photo is served by MediaController, because the public/storage
     * symlink does not exist on this host. If that route stops serving avatars,
     * every screen above shows a broken image.
     */
    public function test_the_stored_photo_is_actually_servable(): void
    {
        $user = $this->consultant();

        $this->actingAs($user)->post(route('profile.avatar'), [
            '_method' => 'PATCH',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $this->actingAs($this->admin())
            ->get(route('media.show', $user->fresh()->avatar))
            ->assertOk();
    }
}
