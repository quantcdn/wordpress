<?php

declare(strict_types=1);

namespace Quant\Tests\Search;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Search\SearchRecordBuilder;
use Quant\Tests\Support\WordPressStubs;
use WP_Post_Type;
use WP_Taxonomy;

#[CoversClass(SearchRecordBuilder::class)]
final class SearchRecordBuilderTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        Functions\when('get_the_title')->alias(static fn(\WP_Post $p): string => $p->post_title . ' &amp; more');
        Functions\when('get_permalink')->justReturn('https://example.com/blog/post-1/');
        Functions\when('get_locale')->justReturn('en_AU');
        Functions\when('has_blocks')->justReturn(false);
        Functions\when('strip_shortcodes')->returnArg();
        Functions\when('wp_trim_words')->alias(static fn(string $t, int $n, string $more): string => implode(' ', array_slice(explode(' ', $t), 0, $n)) . $more);
        Functions\when('get_the_post_thumbnail_url')->justReturn('https://example.com/wp-content/uploads/thumb.jpg');
        Functions\when('get_object_taxonomies')->justReturn([new WP_Taxonomy('category'), new WP_Taxonomy('post_tag'), new WP_Taxonomy('hidden', false)]);
        Functions\when('get_the_terms')->alias(fn(\WP_Post $p, string $tax) => match ($tax) {
            'category' => [$this->makeTerm(1, 'category', 'News &amp; Views'), $this->makeTerm(2, 'category', 'Tech')],
            'post_tag' => false,
            default => [$this->makeTerm(9, $tax, 'Secret')],
        });
        Functions\when('get_post_type_object')->alias(static fn(string $t) => new WP_Post_Type($t));
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page', 'book' => 'book', 'attachment' => 'attachment']);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_builds_a_record_in_the_shared_quant_shape(): void
    {
        $builder = new SearchRecordBuilder($this->mockSettings(search: ['enabled' => true]));
        $post = $this->makePost(1);
        $post->post_excerpt = ' A <b>short</b> summary ';

        $record = $builder->forPost($post);

        $this->assertSame('Post 1 & more', $record['title']);
        $this->assertSame('A short summary', $record['summary']);
        $this->assertSame('Content of post 1', $record['content']);
        $this->assertSame('/blog/post-1', $record['url']);
        $this->assertSame('en', $record['lang_code']);
        $this->assertSame('English', $record['language_en']);
        $this->assertSame('post', $record['content_type']);
        $this->assertSame('Post', $record['content_type_en']);
        $this->assertSame('/wp-content/uploads/thumb.jpg', $record['image']);
        $this->assertSame(['News & Views', 'Tech'], $record['category_en']);
        $this->assertArrayNotHasKey('post_tag_en', $record);
        $this->assertArrayNotHasKey('hidden_en', $record);
        $this->assertTrue($builder->enabled());
    }

    #[Test]
    public function it_falls_back_to_trimmed_content_for_the_summary(): void
    {
        $builder = new SearchRecordBuilder($this->mockSettings(search: ['enabled' => true, 'summary_source' => 'content']));
        $post = $this->makePost(1);
        $post->post_excerpt = 'Ignored';
        $post->post_content = str_repeat('word ', 80);

        $summary = $builder->forPost($post)['summary'];

        $this->assertStringStartsWith('word word', $summary);
        $this->assertStringEndsWith('…', $summary);
        $this->assertLessThan(400, strlen($summary));
    }

    #[Test]
    public function it_skips_post_types_that_are_not_indexed(): void
    {
        $builder = new SearchRecordBuilder($this->mockSettings(search: ['enabled' => true, 'post_types' => ['post']]));

        $this->assertSame(['skip' => true], $builder->forPost($this->makePost(2, 'page')));
        $this->assertSame(['post'], $builder->indexedPostTypes());
    }

    #[Test]
    public function it_indexes_every_public_type_minus_exclusions_when_no_types_are_chosen(): void
    {
        $builder = new SearchRecordBuilder($this->mockSettings(search: ['enabled' => true, 'post_types' => [], 'excluded_post_types' => ['book']]));

        $this->assertSame(['post', 'page'], $builder->indexedPostTypes());
        $this->assertTrue($builder->indexes('page'));
        $this->assertFalse($builder->indexes('book'));
        $this->assertFalse($builder->indexes('attachment'));
    }

    #[Test]
    public function it_keeps_external_images_absolute_and_omits_missing_ones(): void
    {
        $builder = new SearchRecordBuilder($this->mockSettings(search: ['enabled' => true]));

        Functions\when('get_the_post_thumbnail_url')->justReturn('https://cdn.other.com/t.jpg');
        $this->assertSame('https://cdn.other.com/t.jpg', $builder->forPost($this->makePost(1))['image']);

        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        $this->assertArrayNotHasKey('image', $builder->forPost($this->makePost(1)));
    }

    #[Test]
    public function it_reports_the_default_language(): void
    {
        $builder = new SearchRecordBuilder($this->mockSettings());

        $this->assertSame('en', $builder->defaultLanguage());
    }
}
