<?php

namespace App\Services\Assistant;

use App\Services\Assistant\Contracts\AssistantTool;
use InvalidArgumentException;

/**
 * فهرستِ ماژول‌های متصل — لایه‌ی کشفِ داده (بندِ ۴.۶، لایه‌ی ۴).
 *
 * ماژول‌ها از `config('assistant.tools')` می‌آیند و هر کدام پیش از پذیرش سنجیده می‌شود (`ToolDescriptor::validate`).
 * شناسه‌ی تکراری یا کلاسی که قراردادِ `AssistantTool` را ندارد هم رد می‌شود.
 */
final class ToolRegistry
{
    /** @var array<string, AssistantTool> */
    private array $tools = [];

    /** @param  iterable<class-string|AssistantTool>  $tools */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->register(is_string($tool) ? app($tool) : $tool);
        }
    }

    public static function fromConfig(): self
    {
        return new self((array) config('assistant.tools', []));
    }

    public function register(mixed $tool): void
    {
        if (! $tool instanceof AssistantTool) {
            throw new InvalidArgumentException('ماژولِ دستیار باید قراردادِ AssistantTool را پیاده کند.');
        }

        $descriptor = $tool->descriptor();
        $descriptor->validate();

        if (isset($this->tools[$descriptor->id])) {
            throw new InvalidArgumentException("ماژولِ دستیار «{$descriptor->id}» دو بار ثبت شد.");
        }

        $this->tools[$descriptor->id] = $tool;
    }

    /** @return array<string, AssistantTool> */
    public function all(): array
    {
        return $this->tools;
    }

    public function get(string $id): ?AssistantTool
    {
        return $this->tools[$id] ?? null;
    }

    /**
     * ماژول‌ها به ترتیبِ ارتباط با پرسش (بیشترین امتیاز اول)؛ بی‌ربط‌ها کنار.
     *
     * @return list<array{tool: AssistantTool, score: int}>
     */
    public function ranked(Question $question): array
    {
        $ranked = [];
        foreach ($this->tools as $tool) {
            $score = $tool->score($question);
            if ($score > 0) {
                $ranked[] = ['tool' => $tool, 'score' => $score];
            }
        }

        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $ranked;
    }
}
