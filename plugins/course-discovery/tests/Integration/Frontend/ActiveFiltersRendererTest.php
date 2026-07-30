<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\Frontend;

use CourseDiscovery\Domain\Constraint\Constraint;
use CourseDiscovery\Domain\Filter\Filter;
use CourseDiscovery\Domain\Filter\FilterInputType;
use CourseDiscovery\Domain\Filter\FilterKey;
use CourseDiscovery\Domain\Filter\FilterOption;
use CourseDiscovery\Domain\Filter\FilterOptions;
use CourseDiscovery\Domain\Filter\FilterValues;
use CourseDiscovery\Domain\Filter\SearchCriteria;
use CourseDiscovery\Domain\Pagination;
use CourseDiscovery\Filter\FilterRegistry;
use CourseDiscovery\Filter\KeywordFilter;
use CourseDiscovery\Frontend\ActiveFiltersRenderer;
use CourseDiscovery\Frontend\SearchUrls;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

/**
 * Chip labels come from the filter's own options(), which the form has
 * already built for this request, so chips cost no extra queries. The
 * removal URL is built by round-tripping through SearchCriteria rather than
 * editing a query string, so "unset a key whose values are now empty" and
 * "omit defaults" stay in one place -- the domain object.
 */
final class ActiveFiltersRendererTest extends IntegrationTestCase
{
    private const BASE_URL = 'https://example.test/courses/';

    private function registry(): FilterRegistry
    {
        $registry = new FilterRegistry();
        $registry->register(new KeywordFilter());
        $registry->register(new class () implements Filter {
            public function key(): FilterKey
            {
                return FilterKey::fromString('subject');
            }

            public function label(): string
            {
                return 'Subject';
            }

            public function inputType(): FilterInputType
            {
                return FilterInputType::CheckboxGroup;
            }

            public function description(): ?string
            {
                return null;
            }

            public function options(?SearchCriteria $context = null): FilterOptions
            {
                return FilterOptions::fromArray([
                    new FilterOption('10', 'Design'),
                    new FilterOption('20', 'Statistics'),
                ]);
            }

            public function constrain(FilterValues $values): ?Constraint
            {
                return null;
            }
        });

        return $registry;
    }

    private function criteriaWith(string ...$subjects): SearchCriteria
    {
        return SearchCriteria::empty()->withFilter(
            FilterKey::fromString('subject'),
            FilterValues::fromStrings(array_values($subjects))
        );
    }

    /**
     * A registry holding one checkbox filter whose only option carries
     * $label, so an escaping test can drive a hostile label through the same
     * path a provider name or term name takes.
     */
    private function registryWithLabel(string $label): FilterRegistry
    {
        $registry = new FilterRegistry();
        $registry->register(new class ($label) implements Filter {
            public function __construct(private readonly string $optionLabel)
            {
            }

            public function key(): FilterKey
            {
                return FilterKey::fromString('subject');
            }

            public function label(): string
            {
                return 'Subject';
            }

            public function inputType(): FilterInputType
            {
                return FilterInputType::CheckboxGroup;
            }

            public function description(): ?string
            {
                return null;
            }

            public function options(?SearchCriteria $context = null): FilterOptions
            {
                return FilterOptions::fromArray([new FilterOption('10', $this->optionLabel)]);
            }

            public function constrain(FilterValues $values): ?Constraint
            {
                return null;
            }
        });

        return $registry;
    }

    private function render(SearchCriteria $criteria): string
    {
        return (new ActiveFiltersRenderer(new SearchUrls()))
            ->render($this->registry(), $criteria, self::BASE_URL);
    }

    /**
     * The one chip's `<a>` tag, split into href, aria-label and VISIBLE text.
     *
     * Deleting esc_html($chip['label']) from renderChip() used to leave the
     * whole suite green, because every label assertion was satisfied by the
     * aria-label -- which carries the same escaped string. Tests that mean
     * "the visitor sees this" assert on group 3.
     *
     * @return array{href: string, ariaLabel: string, text: string}
     */
    private function chipParts(string $html): array
    {
        $matched = preg_match(
            '/<a class="cd-chip" href="([^"]*)" aria-label="([^"]*)">([^<]*)</',
            $html,
            $match
        );

        self::assertSame(1, $matched, 'Expected a chip anchor with a closed href and a closed aria-label.');

        return ['href' => $match[1], 'ariaLabel' => $match[2], 'text' => $match[3]];
    }

    public function test_nothing_is_rendered_when_no_filter_is_applied(): void
    {
        self::assertSame('', $this->render(SearchCriteria::empty()));
    }

    public function test_a_chip_shows_the_option_label_not_the_raw_value(): void
    {
        $html = $this->render($this->criteriaWith('10'));

        self::assertStringContainsString('Design', $html);
        self::assertStringNotContainsString('>10<', $html);

        // Specifically the chip's own text, not its aria-label: the two carry
        // the same string, so an assertion on the document as a whole cannot
        // tell which of them is missing.
        self::assertSame('Design', $this->chipParts($html)['text']);
    }

    public function test_removing_one_of_two_values_keeps_the_other_in_the_url(): void
    {
        $html = $this->render($this->criteriaWith('10', '20'));

        preg_match_all('/<a class="cd-chip" href="([^"]+)"/', $html, $matches);

        self::assertCount(2, $matches[1], 'One chip per applied value.');

        $first = html_entity_decode($matches[1][0]);

        self::assertStringNotContainsString('subject%5B0%5D=10', $first);
        self::assertStringContainsString('20', $first, 'The value not being removed must survive.');
    }

    public function test_removing_the_only_value_drops_the_key_entirely(): void
    {
        $html = $this->render($this->criteriaWith('10'));

        preg_match('/<a class="cd-chip" href="([^"]+)"/', $html, $match);

        self::assertArrayHasKey(1, $match);
        self::assertStringNotContainsString('subject', html_entity_decode($match[1] ?? ''));
    }

    public function test_a_removal_url_always_returns_to_the_first_page(): void
    {
        $criteria = $this->criteriaWith('10')->withPagination(new Pagination(4, 12));

        $html = $this->render($criteria);

        preg_match('/<a class="cd-chip" href="([^"]+)"/', $html, $match);

        self::assertArrayHasKey(1, $match);
        self::assertStringNotContainsString(
            SearchCriteria::PARAM_PAGE,
            html_entity_decode($match[1] ?? ''),
            'Removing a filter widens the result set, so page 4 of the narrower one is meaningless.'
        );
    }

    public function test_a_value_with_no_matching_option_is_skipped_rather_than_shown_raw(): void
    {
        $html = $this->render($this->criteriaWith('999'));

        self::assertStringNotContainsString(
            'cd-chip',
            $html,
            'A stale term id from a bookmarked URL must not render as a raw slug.'
        );
        self::assertStringContainsString(
            'cd-clear-filters',
            $html,
            'The stale value still constrains the query, so the way out of it must still be offered.'
        );
    }

    /**
     * The dead end this block exists to prevent: a bookmarked URL whose term
     * has since been deleted still narrows the query -- to nothing, usually --
     * while producing no chip to remove. Rendering only when a chip matched
     * left that visitor with an empty result set and no reset anywhere on the
     * page.
     */
    public function test_a_stale_filter_value_still_offers_a_working_clear_all(): void
    {
        $html = $this->render($this->criteriaWith('999'));

        self::assertStringContainsString('<div class="cd-active-filters">', $html);
        self::assertStringNotContainsString(
            '<ul class="cd-active-filters-list">',
            $html,
            'A list of no chips is a list of nothing -- assistive technology announces it as one.'
        );

        $matched = preg_match('/<a class="cd-clear-filters" href="([^"]*)"/', $html, $match);

        self::assertSame(1, $matched, 'Expected a clear-filters link.');

        $href = html_entity_decode($match[1]);

        self::assertSame(
            self::BASE_URL,
            $href,
            'Clear all must resolve against the threaded base URL and drop every param this plugin owns.'
        );
    }

    /**
     * Clear all is built with remove_query_arg() against the threaded base
     * rather than from scratch, so a base that itself carries state -- a
     * plain-permalinks page is `?page_id=42` -- keeps it.
     */
    public function test_clear_all_keeps_params_the_plugin_does_not_own(): void
    {
        $html = (new ActiveFiltersRenderer(new SearchUrls()))->render(
            $this->registry(),
            $this->criteriaWith('10'),
            'https://example.test/?page_id=42'
        );

        $matched = preg_match('/<a class="cd-clear-filters" href="([^"]*)"/', $html, $match);

        self::assertSame(1, $matched, 'Expected a clear-filters link.');

        $href = html_entity_decode($match[1]);

        self::assertStringContainsString('page_id=42', $href, 'A param the plugin does not own must survive.');
        self::assertStringNotContainsString('subject', $href);
    }

    public function test_the_keyword_term_gets_no_chip(): void
    {
        $html = $this->render(SearchCriteria::empty()->withTerm('design'));

        self::assertStringNotContainsString(
            'cd-chip',
            $html,
            'The term is already visible in the hero field; a chip would be a second copy of it.'
        );
        self::assertStringContainsString(
            'cd-clear-filters',
            $html,
            'Clear all clears the term too, so a term-only search still gets the reset.'
        );
    }

    public function test_the_active_count_matches_the_number_of_chips(): void
    {
        $renderer = new ActiveFiltersRenderer(new SearchUrls());

        self::assertSame(0, $renderer->activeCount($this->registry(), SearchCriteria::empty()));
        self::assertSame(2, $renderer->activeCount($this->registry(), $this->criteriaWith('10', '20')));
        self::assertSame(
            0,
            $renderer->activeCount($this->registry(), $this->criteriaWith('999')),
            'The count must apply the same "must match an option" rule the chips do.'
        );
    }

    public function test_a_chip_label_containing_markup_is_escaped(): void
    {
        $label = '"><img src=x onerror=alert(1)>';

        $html = (new ActiveFiltersRenderer(new SearchUrls()))
            ->render($this->registryWithLabel($label), $this->criteriaWith('10'), self::BASE_URL);

        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);

        // Both places the label appears, checked separately: the aria-label
        // alone satisfied every assertion above, so it could not tell an
        // escaped visible label from a missing one.
        $chip = $this->chipParts($html);

        self::assertSame('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $chip['text']);
        self::assertSame($label, html_entity_decode($chip['text']));
        self::assertSame('Remove filter: ' . $label, html_entity_decode($chip['ariaLabel']));
    }

    /**
     * The other half of the same vector: a label does not need a tag to be
     * dangerous, only a double quote, which ends the attribute it lands in and
     * lets everything after it become new attributes on the chip's own <a> --
     * an event handler, for instance. esc_attr() and esc_html() both encode it
     * to &quot;, so the whole label stays one attribute value and one text
     * node.
     */
    public function test_a_chip_label_containing_a_double_quote_cannot_break_out_of_an_attribute(): void
    {
        $label = 'Statistics" onmouseover="alert(1)';

        $html = (new ActiveFiltersRenderer(new SearchUrls()))
            ->render($this->registryWithLabel($label), $this->criteriaWith('10'), self::BASE_URL);

        self::assertStringNotContainsString(
            '" onmouseover="',
            $html,
            'A raw double quote would close the attribute and open an event handler.'
        );
        self::assertStringContainsString('&quot; onmouseover=&quot;alert(1)', $html);

        // Each group is delimited by a real `"` (or `<`), so the label having
        // survived intact inside one of them IS the proof it never escaped it.
        $chip = $this->chipParts($html);

        self::assertSame('Statistics&quot; onmouseover=&quot;alert(1)', $chip['text']);
        self::assertSame($label, html_entity_decode($chip['text']));
        self::assertSame('Remove filter: ' . $label, html_entity_decode($chip['ariaLabel']));
    }
}
