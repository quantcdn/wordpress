<?php

declare(strict_types=1);

namespace Quant\Tests\Search;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Search\SearchPageRepository;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(SearchPageRepository::class)]
final class SearchPageRepositoryTest extends TestCase
{
    use WordPressStubs;

    /** @var array<string, mixed> */
    private array $options = [];
    private SearchPageRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        Functions\when('get_option')->alias(fn(string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });

        $this->repository = new SearchPageRepository();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_saves_a_normalised_page_with_a_generated_id(): void
    {
        $page = $this->repository->save([
            'label' => 'Site Search',
            'route' => ' search/ ',
            'post_types' => ['post', 'Page!'],
            'display' => ['per_page' => 500, 'display_stats' => 0],
            'facets' => [
                ['facet_type' => 'taxonomy', 'taxonomy' => 'category', 'facet_display' => 'menu', 'facet_limit' => 0],
                ['facet_type' => 'bogus', 'facet_display' => 'carousel'],
                'not-a-facet',
            ],
            'unknown' => 'dropped',
        ]);

        $this->assertSame('site-search', $page['id']);
        $this->assertSame('/search', $page['route']);
        $this->assertSame(['post', 'page'], $page['post_types']);
        $this->assertSame(100, $page['display']['per_page']);
        $this->assertFalse($page['display']['display_stats']);
        $this->assertTrue($page['display']['display_search']);
        $this->assertCount(2, $page['facets']);
        $this->assertSame('menu', $page['facets'][0]['facet_display']);
        $this->assertSame(1, $page['facets'][0]['facet_limit']);
        $this->assertSame('taxonomy', $page['facets'][1]['facet_type']);
        $this->assertSame('checkbox', $page['facets'][1]['facet_display']);
        $this->assertArrayNotHasKey('unknown', $page);
        $this->assertTrue($page['status']);
    }

    #[Test]
    public function it_updates_finds_and_deletes_pages(): void
    {
        $a = $this->repository->save(['label' => 'A', 'route' => '/a']);
        $b = $this->repository->save(['label' => 'A', 'route' => '/b']);
        $this->assertSame('a', $a['id']);
        $this->assertSame('a-2', $b['id'], 'Ids stay unique');

        $this->repository->save(['id' => 'a', 'label' => 'A updated', 'route' => '/a', 'page_id' => 42]);

        $this->assertCount(2, $this->repository->all());
        $this->assertSame('A updated', $this->repository->find('a')['label']);
        $this->assertSame('a', $this->repository->findByPostId(42)['id']);
        $this->assertNull($this->repository->findByPostId(7));
        $this->assertTrue($this->repository->delete('a'));
        $this->assertFalse($this->repository->delete('a'));
        $this->assertNull($this->repository->find('a'));
    }

    #[Test]
    public function it_derives_facet_keys_and_filter_strings(): void
    {
        $page = $this->repository->save([
            'label' => 'Filtered',
            'languages' => ['en', 'fr'],
            'post_types' => ['post', 'book'],
            'manual_filters' => "category_en:'News'",
            'facets' => [
                ['facet_type' => 'taxonomy', 'taxonomy' => 'genre'],
                ['facet_type' => 'content_type'],
                ['facet_type' => 'language'],
                ['facet_type' => 'custom', 'custom_key' => 'author_en'],
            ],
        ]);

        $this->assertSame(['lang_code', 'content_type', 'genre_en', 'content_type_en', 'language_en', 'author_en'], $this->repository->facetKeys($page, 'en'));
        $this->assertSame("(lang_code:'en' OR lang_code:'fr') AND (content_type:'post' OR content_type:'book') AND category_en:'News'", $this->repository->filterString($page));
        $this->assertSame('', $this->repository->filterString($this->repository->save(['label' => 'Open'])));
        $this->assertSame("(lang_code:=`en` || lang_code:=`fr`) && (content_type:=`post` || content_type:=`book`) && category_en:'News'", $this->repository->filterString($page, 'typesense'));
    }

    #[Test]
    public function first_enabled_page_skips_a_disabled_first_page(): void
    {
        $this->options[SearchPageRepository::OPTION] = [
            ['id' => 'old', 'label' => 'Old', 'route' => '/old-search', 'status' => false],
            ['id' => 'site', 'label' => 'Site', 'route' => '/search', 'status' => true],
        ];

        $page = $this->repository->defaultPage(['enabled' => true, 'replace_wp_search' => true, 'default_page' => '']);

        $this->assertSame('site', $page['id'] ?? null);
    }

    #[Test]
    public function first_enabled_page_is_null_when_every_page_is_disabled(): void
    {
        $this->options[SearchPageRepository::OPTION] = [
            ['id' => 'old', 'label' => 'Old', 'route' => '/old-search', 'status' => false],
            ['id' => 'site', 'label' => 'Site', 'route' => '/search', 'status' => false],
        ];

        $this->assertNull($this->repository->defaultPage(['enabled' => true, 'replace_wp_search' => true, 'default_page' => '']));
    }

    #[Test]
    public function a_disabled_chosen_default_page_is_null_not_another_page(): void
    {
        $this->options[SearchPageRepository::OPTION] = [
            ['id' => 'old', 'label' => 'Old', 'route' => '/old-search', 'status' => false],
            ['id' => 'site', 'label' => 'Site', 'route' => '/search', 'status' => true],
        ];

        $this->assertNull($this->repository->defaultPage(['enabled' => true, 'replace_wp_search' => true, 'default_page' => 'old']));
    }

    #[Test]
    public function there_is_no_default_page_while_the_option_is_off(): void
    {
        $this->options[SearchPageRepository::OPTION] = [['id' => 'site', 'label' => 'Site', 'route' => '/search', 'status' => true]];

        $this->assertNull($this->repository->defaultPage(['enabled' => true, 'replace_wp_search' => false, 'default_page' => '']));
        $this->assertNull($this->repository->defaultPage(['enabled' => false, 'replace_wp_search' => true, 'default_page' => '']));
    }

    #[Test]
    public function it_ignores_corrupt_option_values(): void
    {
        $this->options[SearchPageRepository::OPTION] = 'garbage';
        $this->assertSame([], $this->repository->all());

        $this->options[SearchPageRepository::OPTION] = ['x', ['label' => 'Ok']];
        $this->assertCount(1, $this->repository->all());
    }
}
