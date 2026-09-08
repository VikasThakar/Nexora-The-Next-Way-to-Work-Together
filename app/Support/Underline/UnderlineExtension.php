<?php

declare(strict_types=1);

namespace App\Support\Underline;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Adds `++underline++` to the application's Markdown flavour.
 *
 * Registered in App\Support\Markdown, so every place prose is rendered — ticket
 * descriptions, comments, documentation, AI answers — understands it
 * identically. Adding it in one environment and not another would mean the
 * ticket screen and the board card disagreed about the same stored text.
 *
 * See App\Support\Underline\Underline for why this syntax exists at all, and
 * UnderlineDelimiterProcessor for why it cannot eat "C++".
 */
final class UnderlineExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addDelimiterProcessor(new UnderlineDelimiterProcessor);
        $environment->addRenderer(Underline::class, new UnderlineRenderer);
    }
}
