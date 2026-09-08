<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Enums\ThemePreference;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Choosing an appearance, and having that choice remembered.
 *
 * The appearance itself is applied in the browser, so what can be tested here
 * is the half the server owns: that the preference is stored, that only the
 * three known values can be stored, and that a page renders the stored value
 * into the one script that runs before the stylesheet arrives.
 *
 * That last one is the interesting assertion. The no-flash guarantee depends
 * entirely on the preference reaching the document head — if it stops being
 * rendered there, the application still works and still remembers the choice,
 * and every page load flashes white on the way to dark. Nothing else would
 * fail, which is exactly why it is asserted.
 */
class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_account_gets_the_light_appearance(): void
    {
        // Light rather than follow-the-device, because light is what the
        // product looked like before this setting existed — applying the
        // migration must not change what anybody sees.
        $this->assertSame(ThemePreference::Light, $this->teamMember()->theme_preference);
        $this->assertSame(ThemePreference::Light, ThemePreference::default());
    }

    public function test_the_preference_is_cast_to_the_enum(): void
    {
        $user = $this->teamMember();

        $user->theme_preference = ThemePreference::Dark;
        $user->save();

        $this->assertSame(ThemePreference::Dark, $user->fresh()->theme_preference);
    }

    public function test_each_appearance_can_be_chosen(): void
    {
        $user = $this->teamMember();

        foreach (ThemePreference::cases() as $preference) {
            $this->actingAs($user)
                ->putJson(route('settings.theme'), ['theme' => $preference->value])
                ->assertOk()
                ->assertJson(['theme' => $preference->value]);

            $this->assertSame($preference, $user->fresh()->theme_preference);
        }
    }

    /**
     * The value is rendered into a class attribute in the document head, so an
     * unvalidated string reaching the column would be reflected into the page.
     */
    public function test_an_unknown_appearance_is_refused(): void
    {
        $user = $this->teamMember();

        foreach (['', 'neon', 'DARK', 'system;', '<script>', 'light dark'] as $value) {
            $this->actingAs($user)
                ->putJson(route('settings.theme'), ['theme' => $value])
                ->assertStatus(422);
        }

        // Still the default: nothing above was stored.
        $this->assertSame(ThemePreference::Light, $user->fresh()->theme_preference);
    }

    public function test_a_guest_cannot_store_a_preference(): void
    {
        $this->putJson(route('settings.theme'), ['theme' => 'dark'])
            ->assertUnauthorized();
    }

    /**
     * The column is deliberately absent from User::$fillable, in the same way
     * `role` is. Asserted because adding it would be an easy, invisible way to
     * make a request able to write it.
     *
     * This application enables preventSilentlyDiscardingAttributes(), so the
     * attempt throws rather than being quietly dropped — which is the stronger
     * of the two behaviours and worth pinning down as the one in force.
     */
    public function test_the_column_is_not_mass_assignable(): void
    {
        $user = $this->teamMember();

        $this->expectException(MassAssignmentException::class);

        $user->fill(['theme_preference' => 'dark']);
    }

    /**
     * The default is written in three places — the enum, the column, and the
     * model's $attributes — because each covers a case the others do not. This
     * is the guard against them drifting apart.
     */
    public function test_the_model_default_agrees_with_the_enum(): void
    {
        $this->assertSame(
            ThemePreference::default()->value,
            (new User)->getAttributes()['theme_preference'] ?? null,
        );

        // And a model that has never touched the database still has one, so
        // the layout always has a preference to render.
        $this->assertSame(ThemePreference::Light, (new User)->theme_preference);
    }

    // -----------------------------------------------------------------
    // What reaches the page
    // -----------------------------------------------------------------

    public function test_a_page_renders_the_stored_preference_before_the_stylesheet(): void
    {
        $user = $this->teamMember();
        $user->theme_preference = ThemePreference::Dark;
        $user->save();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        // The value is in the document...
        $this->assertStringContainsString('"dark"', $html);

        // ...and it is ahead of the stylesheet, which is the whole point. A
        // preference read after the CSS has painted is a preference that
        // flashes.
        $boot = strpos($html, 'nexora.theme');
        $css = strpos($html, 'app.css');

        $this->assertNotFalse($boot, 'The appearance boot script is missing.');
        $this->assertNotFalse($css, 'The stylesheet link is missing.');
        $this->assertLessThan($css, $boot, 'The boot script must come before the stylesheet.');
    }

    public function test_the_write_endpoint_is_named_for_a_signed_in_viewer_only(): void
    {
        $user = $this->teamMember();

        $signedIn = $this->actingAs($user)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('themeEndpoint', $signedIn);

        // A guest's page has nothing to write to, so it does not name the
        // route — which is how resources/js/theme.js knows not to post.
        $guest = $this->get(route('login'))->getContent();
        $this->assertStringNotContainsString('themeEndpoint', $guest);
    }

    /**
     * `system` must not be resolved on the server.
     *
     * Only the browser knows what the device currently prefers, and it can
     * change while the page is open. Rendering `dark` for somebody whose
     * preference is `system` would be right at dusk and wrong by morning.
     */
    public function test_system_is_rendered_as_system_rather_than_resolved(): void
    {
        $user = $this->teamMember();
        $user->theme_preference = ThemePreference::System;
        $user->save();

        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('"system"', $html);
        $this->assertNull(ThemePreference::System->htmlClass());
    }

    public function test_the_sidebar_offers_all_three_appearances(): void
    {
        $html = $this->actingAs($this->teamMember())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertStringContainsString('aria-label="Appearance"', $html);

        foreach (ThemePreference::ordered() as $preference) {
            $this->assertStringContainsString($preference->label(), $html);
            // The spoken description, which the one-word label leaves out.
            $this->assertStringContainsString($preference->description(), $html);
        }
    }

    public function test_the_guest_layout_boots_the_appearance_too(): void
    {
        // Otherwise the sign-in screen is the one page in the product that
        // flashes, and it is the first one anybody sees.
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('nexora.theme', escape: false);
    }
}
