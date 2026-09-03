<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ActivityCategory;
use App\Enums\ActivityType;
use App\Support\ActivityFilters;
use PHPUnit\Framework\TestCase;

/**
 * The invariants the Activity screen's one filter parameter depends on.
 *
 * `?type=` accepts either a category or a single type and resolves a category
 * first (App\Models\Activity::ofType). That is only unambiguous while the two
 * enums share no values, and only complete while every type has a category and
 * a rendering — none of which the type system can enforce on its own.
 */
class ActivityTaxonomyTest extends TestCase
{
    public function test_no_category_value_collides_with_a_type_value(): void
    {
        $collisions = array_intersect(ActivityCategory::values(), ActivityType::values());

        $this->assertSame(
            [],
            $collisions,
            'A category and a type share a value, so the type filter is ambiguous: '
                .implode(', ', $collisions)
        );
    }

    public function test_every_type_files_under_a_category_and_renders(): void
    {
        foreach (ActivityType::cases() as $type) {
            // category() is exhaustive with no default arm, so this would throw
            // rather than fail — which is the point of asserting it here.
            $this->assertInstanceOf(ActivityCategory::class, $type->category());

            $this->assertNotSame('', trim($type->label()), $type->value.' has no label.');
            $this->assertNotSame('', trim($type->icon()), $type->value.' has no icon.');
            $this->assertNotSame('', trim($type->tone()), $type->value.' has no tone.');
        }
    }

    public function test_every_tone_is_a_variant_the_badge_component_offers(): void
    {
        // The feed must not introduce a colour the design system does not have;
        // x-ui.badge silently falls back to slate, which would make a typo
        // invisible rather than loud.
        $available = ['slate', 'brand', 'emerald', 'amber', 'rose'];

        foreach (ActivityType::cases() as $type) {
            $this->assertContains(
                $type->tone(),
                $available,
                $type->value.' uses a badge variant that does not exist: '.$type->tone()
            );
        }
    }

    public function test_every_category_is_reachable_from_at_least_one_type(): void
    {
        foreach (ActivityCategory::cases() as $category) {
            $this->assertNotSame(
                [],
                ActivityType::valuesIn($category),
                $category->value.' is offered as a filter but nothing files under it.'
            );
        }
    }

    public function test_the_filter_dropdown_only_offers_values_the_query_understands(): void
    {
        foreach (ActivityFilters::typeOptions() as $group => $options) {
            $this->assertNotSame([], $options, 'The "'.$group.'" filter group is empty.');

            foreach (array_keys($options) as $value) {
                $this->assertTrue(
                    ActivityFilters::isKnownType((string) $value),
                    $value.' is offered in the dropdown but the query would discard it.'
                );
            }
        }
    }

    public function test_internal_only_values_are_a_subset_of_the_known_types(): void
    {
        $this->assertNotSame([], ActivityType::internalOnlyValues());

        foreach (ActivityType::internalOnlyValues() as $value) {
            $this->assertNotNull(ActivityType::tryFrom($value));
        }
    }

    public function test_the_filterable_shortcuts_are_all_real_types(): void
    {
        $this->assertNotSame([], ActivityType::filterable());

        foreach (ActivityType::filterable() as $type) {
            $this->assertInstanceOf(ActivityType::class, $type);
        }
    }
}
