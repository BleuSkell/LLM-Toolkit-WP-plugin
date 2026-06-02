<?php
function get_signatures(): array {
    return [
        'crawler'               => '/GPTBot|OAI-AdsBot|ClaudeBot|claude-web|anthropic-ai|Google-Extended|cohere-ai|DeepSeekBot|GrokBot|MistralAI-Index/crawler/i',
        'answer_engine'         => '/OAI-SearchBot|ChatGPT-User|Claude-User|Claude-SearchBot|Google-Agent|PerplexityBot|Perplexity-User|MistralAI-User|xAI-Grok|Grok-DeepSearch/i',
        'trad_search_crawler'   => '/Googlebot|bingbot|Slurp|DuckDuckBot|Baiduspider|YandexBot|facebot|ia_archiver/i',
        'ai_referral'           => '/chatgpt\.com|claude\.ai|perplexity\.ai|gemini\.google\.com|copilot\.microsoft\.com|grok\.com|mistral\.ai|deepseek\.com/i',
    ];
}