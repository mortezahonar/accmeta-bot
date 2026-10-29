<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/ArticleSource.php';
require __DIR__ . '/../src/ArticleSourceRegistry.php';
require __DIR__ . '/../src/ArticleService.php';

final class RecordingArticleGenerator implements TextGenerator
{
    /** @var list<string> */
    public array $inputs = [];

    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $this->inputs[] = $userText;
        return $userText;
    }
}

final class BatchTestArticleSource implements ArticleSource
{
    public function supportsUrl(string $url): bool
    {
        return parse_url($url, PHP_URL_HOST) === 'accounting.example'
            && str_starts_with((string) parse_url($url, PHP_URL_PATH), '/articles/');
    }

    public function topicKey(string $url): string
    {
        return $this->supportsUrl($url) ? 'test-sample-article' : '';
    }

    public function fetch(string $url): array
    {
        $first = str_repeat('A', 900);
        $second = str_repeat('B', 900);
        $third = str_repeat('C', 900);
        $afterImage = str_repeat('D', 100);
        return [
            'title' => 'Sample article',
            'author' => 'Test author',
            'author_url' => '',
            'published' => '2026-10-01',
            'lead_image' => '',
            'body_empty' => false,
            'tags' => [],
            'blocks' => [
                ['type' => 'text', 'html' => '<p>' . $first . '</p>'],
                ['type' => 'text', 'html' => '<p>' . $second . '</p>'],
                ['type' => 'text', 'html' => '<p>' . $third . '</p>'],
                ['type' => 'img', 'src' => 'https://accounting.example/images/sample.jpg', 'alt' => '2026 Tips'],
                ['type' => 'text', 'html' => '<p>' . $afterImage . '</p>'],
            ],
        ];
    }

    public function resolveUrl(string $src, string $articleUrl): string
    {
        if (str_starts_with($src, '/')) {
            return 'https://accounting.example' . $src;
        }
        return $src;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$stateDir = sys_get_temp_dir() . '/accmeta-article-test-' . bin2hex(random_bytes(6));
$store = new Store($stateDir);
register_shutdown_function(static function () use ($stateDir): void {
    foreach (['topics', 'pending', 'awaiting', 'links'] as $directory) {
        @rmdir($stateDir . '/' . $directory);
    }
    @rmdir($stateDir);
});

$generator = new RecordingArticleGenerator();
$translator = new Translator($generator, 'test system prompt');
$sources = new ArticleSourceRegistry(new BatchTestArticleSource());
$service = new ArticleService($translator, $store, $sources);
$plan = $service->plan('https://accounting.example/articles/sample-article', false);

$assert(count($generator->inputs) === 5, 'title, image caption, and three bounded article text units should require five generator calls');
$assert($generator->inputs[0] === 'Sample article', 'the topic title should be translated independently');
$assert(
    str_contains($generator->inputs[1], str_repeat('A', 900)) && str_contains($generator->inputs[1], str_repeat('B', 900))
        && !str_contains($generator->inputs[1], str_repeat('C', 900)),
    'adjacent paragraphs should be batched up to the input threshold'
);
$assert($generator->inputs[2] === str_repeat('C', 900), 'the next paragraph should start a new bounded unit');
$assert($generator->inputs[3] === '2026 Tips', 'the image alt text should be translated as its caption');
$assert($generator->inputs[4] === str_repeat('D', 100), 'text after a figure should be translated after the image boundary');
$assert(
    array_column($plan['actions'], 'kind') === ['topic', 'message', 'message', 'photo', 'message'],
    'the action plan must preserve text/image order without source-specific tags'
);
$photos = array_values(array_filter($plan['actions'], static fn (array $action): bool => $action['kind'] === 'photo'));
$assert(count($photos) === 1 && $photos[0]['url'] === 'https://accounting.example/images/sample.jpg', 'relative images should resolve against the article source');
$assert($photos[0]['caption'] === Persian::RLM . '2026 Tips', 'numeric-leading photo captions must receive an RTL mark');
$assert($plan['slug'] === 'test-sample-article', 'the planner should use the source adapter topic key');

fwrite(STDOUT, "OK: article batching, input threshold and image ordering tests passed.\n");
