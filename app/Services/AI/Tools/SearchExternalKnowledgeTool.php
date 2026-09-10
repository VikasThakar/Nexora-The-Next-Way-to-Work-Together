<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\AI\Exceptions\ExternalKnowledgeException;
use App\Services\AI\Knowledge\ExternalKnowledgeProviderInterface;
use App\Services\AI\Knowledge\ExternalKnowledgeResult;

/**
 * Look something up outside the workspace.
 *
 * The only tool in the registry that reads nothing belonging to this
 * application, which makes it the odd one out in every direction — and the
 * reason it is a tool at all rather than a branch somewhere is that being a
 * tool is what makes it *withholdable*. Under Project scope this class is never
 * in the list handed to the model, so a question that would need an outside
 * lookup cannot produce one: there is no capability to call. That is the data
 * layer enforcing the setting, and it is stronger than a prompt saying no.
 *
 * Every other rule in AiToolContract still applies, and two of them apply
 * particularly hard here.
 *
 * The subject comes from the arguments, the authority from the context
 * ------------------------------------------------------------------
 * The argument is a search phrase. There is no argument that can widen what
 * this returns, because what it returns has nothing to do with this workspace —
 * and that is exactly why the availability check matters so much. A tool whose
 * results are not bounded by a reader is a tool that must be bounded by whether
 * it exists.
 *
 * Nothing from the workspace goes out
 * -----------------------------------
 * The query is capped at a search phrase's length by the schema — AiToolInput
 * truncates to `maxLength` before this class is entered — and the tool
 * description tells the model to send a phrase rather than a paste. That is the
 * honest position: the model composes the query, so this is not a guarantee
 * about intent. It is a guarantee about volume, which is the one that turns a
 * leak from a matter of degree into a bounded one: a ticket description does
 * not fit in 200 characters, and a ticket key on its own says nothing to a
 * search engine.
 *
 * Not configured is answered, not thrown
 * --------------------------------------
 * A deployment with no provider is the normal case. It is reported as a
 * refusal with prose the model can act on — answer from what you know, and say
 * the lookup did not happen — because the alternative is an assistant that
 * either dies on the question or, far worse, invents the answer it could not
 * look up.
 */
class SearchExternalKnowledgeTool implements AiToolContract
{
    /**
     * The name the model is given.
     *
     * A constant as well as a method because one other place needs to ask
     * "was this particular tool offered?" — App\Services\AI\WorkspaceChatService,
     * which has to tell the prompt whether a live lookup really exists. A
     * string literal there would be a second copy of this name, and a renamed
     * tool would silently leave the prompt describing a capability that was
     * never sent.
     */
    public const NAME = 'search_external_knowledge';

    /**
     * A search phrase, not a document.
     *
     * The cap is the outbound-data bound described above, so it is deliberately
     * tight: long enough for any question somebody would type into a search
     * box, short enough that no meaningful amount of workspace content fits.
     */
    private const MAX_QUERY = 200;

    private const MAX_RESULTS = 8;

    private const DEFAULT_RESULTS = 5;

    public function __construct(
        private readonly ExternalKnowledgeProviderInterface $knowledge,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Look something up OUTSIDE this workspace — general technical knowledge, a library\'s '
            .'documentation, a current fact. Returns titles, sources and short extracts from an '
            .'external service. This knows nothing about this workspace: for anything about a board, a '
            .'ticket, this project\'s documentation or its repositories, use the workspace tools '
            .'instead. Send a short search phrase, never a paste of workspace content. Use it only when '
            .'the question genuinely needs information you do not already have, and attribute what you '
            .'take from it to its source rather than to the project.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'maxLength' => self::MAX_QUERY,
                    'description' => 'A short search phrase. Not workspace content.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_RESULTS,
                    'description' => 'How many results to return. Defaults to '.self::DEFAULT_RESULTS.'.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * Offered only when the conversation is allowed outside, and only when
     * there is somewhere to go.
     *
     * Two conditions, both necessary and for different reasons. The scope is
     * the person's own choice and is the one that makes this feature a choice
     * at all. The provider check is honesty: offering a lookup that will
     * certainly refuse teaches the model to keep trying it, and every attempt
     * is a round of the loop spent on a call that cannot work.
     *
     * Note what is NOT here: no role check and no capability-mode check. This
     * tool reads no workspace content, so there is nothing for a customer to
     * be kept out of — a customer asking what Laravel is has asked a question
     * about Laravel. What a customer may see and what they may change are
     * decided where they are decided everywhere else, and neither of those
     * decisions is reachable from here.
     */
    public function availableTo(AiToolContext $context): bool
    {
        return $context->allowsExternalKnowledge() && $this->knowledge->isConfigured();
    }

    public function handle(array $input, AiToolContext $context): AiToolOutcome
    {
        $query = trim((string) ($input['query'] ?? ''));

        if ($query === '') {
            return AiToolOutcome::invalid('Give a search phrase to look up.');
        }

        $limit = (int) ($input['limit'] ?? self::DEFAULT_RESULTS);
        $limit = max(1, min(self::MAX_RESULTS, $limit));

        try {
            $results = $this->knowledge->search($query, $limit);
        } catch (ExternalKnowledgeException $exception) {
            /*
             * Refused rather than failed.
             *
             * "failed" is for a fault, and an unconfigured deployment is not
             * one — it is a deployment that has not bought a search service.
             * The distinction shows up in the audit trail, where a column full
             * of errors would bury the real ones.
             */
            return AiToolOutcome::refused($exception->getMessage(), $this->knowledge->name());
        }

        $results = array_values(array_filter(
            $results,
            static fn (mixed $result): bool => $result instanceof ExternalKnowledgeResult
        ));

        if ($results === []) {
            return AiToolOutcome::notFound(
                'The external lookup for "'.$query.'" returned nothing. Say that you found nothing '
                .'about it outside the workspace rather than filling the gap.',
                $this->knowledge->name(),
            );
        }

        $lines = array_map(
            static fn (ExternalKnowledgeResult $result): string => $result->toText(),
            array_slice($results, 0, $limit),
        );

        return AiToolOutcome::ok(
            'EXTERNAL SOURCES for "'.$query.'" — from outside this workspace, via '
            .$this->knowledge->name().'; NOT project data. Attribute anything you take from these to '
            ."its source, and never present it as a fact about this project.\n\n"
            .implode("\n", $lines),
            $this->knowledge->name(),
            count($results).' external result(s)',
        );
    }
}
