<?php

namespace App\Services\Blog;

use App\Support\PersianText;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Text;

/**
 * مارک‌داونِ نویسنده → HTMLِ امن + فهرستِ مطالب + متنِ جست‌وجو
 * (مرحلهٔ ۶ · فاز ۱۱).
 *
 * ─── 🔴 این‌جا مرزِ امنیتیِ بلاگ است ─────────────────────────────────────────
 * خروجی با `{!! !!}` در صفحه‌ی عمومی چاپ می‌شود، پس هر چیزی که از این‌جا رد
 * شود روی دامنه‌ی ما اجرا می‌شود. سه قاعده:
 *
 * ۱. HTMLِ خام **escape** می‌شود (نه حذف): `<script>` در متن، متن می‌ماند.
 * ۲. پیوندِ ناامن (`javascript:`، `data:`، `vbscript:`) پذیرفته نمی‌شود.
 * ۳. تصویر فقط از `/blog/media/` خودمان؛ تصویرِ بیرونی به متنِ جایگزینش تبدیل
 *    می‌شود. تصویرِ بیرونی هم ردیابی است (هر بازدید IPِ خواننده را به میزبانِ
 *    دیگری می‌دهد) و هم CSP به‌هرحال بلاکش می‌کرد و جایش خالی می‌ماند.
 *
 * پیوندِ بیرونی `nofollow noopener noreferrer` و پنجره‌ی تازه می‌گیرد.
 *
 * ⚠️ این کلاس فقط هنگامِ **ذخیره** صدا زده می‌شود؛ خروجی در ستون می‌ماند.
 */
class BlogRenderer
{
    public const MEDIA_PREFIX = '/blog/media/';

    /** سرعتِ خواندنِ فارسی، واژه در دقیقه. */
    private const WORDS_PER_MINUTE = 200;

    /**
     * @return array{html: string, toc: array<int, array{level: int, id: string, text: string}>, text: string, minutes: int}
     */
    public function render(string $markdown): array
    {
        $html = trim($this->converter()->convert($markdown)->getContent());
        $text = $this->plainText($html);

        return [
            'html' => $html,
            'toc' => $this->toc($html),
            'text' => $text,
            'minutes' => max(1, (int) ceil(count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) / self::WORDS_PER_MINUTE)),
        ];
    }

    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'heading_permalink' => [
                // شناسه روی خودِ عنوان؛ پیوندِ «¶» اضافه نمی‌شود
                'insert' => 'none',
                'apply_id_to_heading' => true,
                'id_prefix' => 'h',
                'fragment_prefix' => 'h',
                'min_heading_level' => 2,
                'max_heading_level' => 3,
            ],
            'external_link' => [
                'internal_hosts' => [(string) parse_url((string) config('app.url'), PHP_URL_HOST)],
                'open_in_new_window' => true,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);
        $environment->addExtension(new ExternalLinkExtension);

        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            foreach ($event->getDocument()->iterator() as $node) {
                if (! $node instanceof Image) {
                    continue;
                }

                if (! str_starts_with($node->getUrl(), self::MEDIA_PREFIX) || str_contains($node->getUrl(), '..')) {
                    $alt = trim(implode('', array_map(
                        fn ($child) => $child instanceof Text ? $child->getLiteral() : '',
                        iterator_to_array($node->children()),
                    )));
                    $node->replaceWith(new Text($alt));

                    continue;
                }

                $node->data->set('attributes/loading', 'lazy');
                $node->data->set('attributes/decoding', 'async');
            }
        });

        return new MarkdownConverter($environment);
    }

    /** @return array<int, array{level: int, id: string, text: string}> */
    private function toc(string $html): array
    {
        preg_match_all('/<h([23]) id="([^"]+)">(.*?)<\/h\1>/su', $html, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): array => [
            'level' => (int) $m[1],
            'id' => html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'text' => trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ], $matches);
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', PersianText::normalize($text)));
    }
}
