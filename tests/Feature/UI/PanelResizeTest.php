<?php

declare(strict_types=1);

namespace Tests\Feature\UI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two panels of the shell can be dragged wider and narrower.
 *
 * What the drag itself feels like is a browser question and there is no
 * JavaScript harness in this project to ask it. What is checked here is the
 * wiring the drag is built on, all of which is markup somebody could quietly
 * drop while restyling the shell — and three facts in particular that look like
 * stylistic choices and are not:
 *
 *   the width must be a custom property, because a utility cannot be dragged;
 *   the restore must be a <style> element, because an inline style on <html> is
 *   deleted by the next wire:navigate;
 *   the content column must not carry a transition utility, because a cascade
 *   layer beats specificity and the rule that suspends it during a drag lives
 *   in a layer that would lose.
 *
 * Each of those would leave every other test in this file passing while the
 * feature misbehaved in a way only a person at a browser would notice.
 */
class PanelResizeTest extends TestCase
{
    use RefreshDatabase;

    private function shell(): string
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        return $this->actingAs($team)->get(route('dashboard'))->assertOk()->getContent();
    }

    // -----------------------------------------------------------------
    // The handles
    // -----------------------------------------------------------------

    public function test_the_navigation_carries_a_resize_handle(): void
    {
        $html = $this->shell();

        $this->assertStringContainsString('x-data="panelResize({ panel: \'nav\' })"', $html);
        $this->assertStringContainsString('aria-label="Resize navigation"', $html);
        $this->assertStringContainsString('aria-controls="app-navigation"', $html);
        $this->assertStringContainsString('id="app-navigation"', $html);
    }

    /**
     * Exactly one, which is the whole reason the handle is a sibling of
     * <x-app.sidebar /> rather than part of it.
     *
     * That component is rendered twice — the desktop column and the off-canvas
     * drawer — so a handle inside it would put two separators in the document
     * and two elements answering to the same `aria-controls` target, one of
     * them in a drawer that cannot be resized at all.
     */
    public function test_the_navigation_handle_is_not_duplicated_into_the_mobile_drawer(): void
    {
        $html = $this->shell();

        $this->assertSame(
            1,
            substr_count($html, 'panelResize({ panel: \'nav\' })'),
            'The navigation handle belongs to the desktop column, not to the sidebar component.',
        );
    }

    public function test_the_assistant_carries_its_own_resize_handle(): void
    {
        $html = $this->shell();

        $this->assertStringContainsString('x-data="panelResize({ panel: \'ai\' })"', $html);
        $this->assertStringContainsString('aria-label="Resize Workspace AI"', $html);
        $this->assertStringContainsString('aria-controls="ai-panel"', $html);
        $this->assertStringContainsString('id="ai-panel"', $html);
    }

    /**
     * A separator a keyboard cannot reach puts a preference behind a pointing
     * device, so the role and the tabindex are asserted together.
     */
    public function test_each_handle_is_an_operable_separator(): void
    {
        $html = $this->shell();

        $this->assertSame(2, substr_count($html, 'role="separator"'));
        $this->assertSame(2, substr_count($html, 'aria-orientation="vertical"'));
        $this->assertSame(2, substr_count($html, 'x-data="panelResize({'));

        // Both of them focusable, and both of them desktop-only: below `lg` the
        // navigation is a drawer and the assistant a full-width sheet, and
        // neither is resizable.
        $this->assertStringContainsString('tabindex="0"', $html);
        $this->assertSame(2, substr_count($html, 'hidden w-3 cursor-col-resize touch-none'));
    }

    // -----------------------------------------------------------------
    // The widths themselves
    // -----------------------------------------------------------------

    /**
     * A custom property rather than a width utility, because a utility is a
     * fixed number in a compiled stylesheet and there is nothing to drag.
     */
    public function test_both_panels_are_sized_by_a_custom_property(): void
    {
        $html = $this->shell();

        $this->assertStringContainsString('lg:w-[var(--nav-width)]', $html);
        $this->assertStringContainsString('lg:max-w-[var(--ai-panel-width)]', $html);

        $this->assertStringNotContainsString(
            'hidden w-64 shrink-0',
            $html,
            'The navigation column should no longer carry a fixed width.',
        );
    }

    /**
     * The content column's padding transition must not be a utility.
     *
     * Suspending it for the length of a drag is a rule in the `components`
     * layer, a utility lives in `utilities`, and a cascade layer beats
     * specificity outright — so while the transition was a utility the
     * suspension could never win, and the page would lag a fifth of a second
     * behind the pointer. Both halves now live in the stylesheet, where
     * specificity decides.
     */
    public function test_the_content_column_does_not_carry_a_transition_utility(): void
    {
        $html = $this->shell();

        $this->assertStringContainsString('class="app-content flex min-w-0 flex-1 flex-col"', $html);
        $this->assertStringNotContainsString('transition-[padding]', $html);
    }

    // -----------------------------------------------------------------
    // Remembering it
    // -----------------------------------------------------------------

    /**
     * The restore has to be a <style> element, not a style attribute on <html>.
     *
     * Livewire's navigation copies the incoming document's <html> attributes
     * over the live ones and then removes every attribute the new element does
     * not have. The layout renders no style attribute there, so an inline
     * custom property would be deleted by the first link anybody clicked, and
     * the panels would snap back to their defaults mid-session. A <head> <style>
     * is kept instead, because Livewire treats it as an asset.
     */
    public function test_the_widths_are_restored_into_a_style_element(): void
    {
        $html = $this->shell();

        $this->assertStringContainsString("getElementById('nexora-panels')", $html);
        $this->assertStringContainsString("createElement('style')", $html);
        $this->assertStringContainsString(':root{}', $html);
        $this->assertStringContainsString('nexora.panels', $html);
    }

    /**
     * And it has to run before the bundle, or the first paint is at the default
     * width and the remembered one arrives as a visible jump.
     *
     * tests/Feature/Theme/ThemePreferenceTest makes the same assertion about
     * the appearance by looking for the literal string "app.css", which is why
     * it fails whenever a production build is present: Vite emits a hashed
     * name. The tag is found here by the one part of it that does not change.
     */
    public function test_the_restore_runs_before_the_bundle(): void
    {
        $html = $this->shell();

        $boot = strpos($html, 'nexora.panels');

        // A built manifest gives /build/assets/app-<hash>.css; the dev server
        // gives the Vite client instead. Either is "the bundle".
        $bundle = strpos($html, '/build/assets/');

        if ($bundle === false) {
            $bundle = strpos($html, '@vite/client');
        }

        $this->assertNotFalse($boot, 'The panel width boot script is missing.');
        $this->assertNotFalse($bundle, 'Neither a built asset nor the Vite client is present.');
        $this->assertLessThan($bundle, $boot, 'The boot script must come before the bundle.');
    }

    // -----------------------------------------------------------------
    // Where it does not belong
    // -----------------------------------------------------------------

    public function test_the_sign_in_pages_have_neither_handle_nor_restore(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('panelResize', $html);
        $this->assertStringNotContainsString('nexora.panels', $html);
        $this->assertStringNotContainsString('role="separator"', $html);
    }
}
