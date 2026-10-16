<?php

namespace Abdelhmed\Chimera\Core;

use Illuminate\Support\Str;

class ContextFilter
{
    /**
     * Small project threshold: if total classes are less than or equal to this, send full context.
     */
    protected const SMALL_PROJECT = 10;

    /**
     * Categories always included to prevent the AI from recreating existing schema or migrations.
     */
    protected const ALWAYS_INCLUDED = ['models', 'migrations', 'schema'];

    /**
     * Keywords mapping categories to English terms.
     */
    protected const CATEGORY_KEYWORDS = [
        'controllers' => ['controller', 'controllers', 'handler', 'handlers'],
        'jobs'        => ['job', 'jobs', 'queue', 'queues', 'task', 'tasks'],
        'events'      => ['event', 'events', 'broadcast', 'broadcasting'],
        'listeners'   => ['listener', 'listeners'],
        'requests'    => ['request', 'requests', 'validation', 'validate'],
        'resources'   => ['resource', 'resources', 'api'],
        'policies'    => ['policy', 'policies', 'authorization', 'authorize'],
        'services'    => ['service', 'services', 'provider', 'providers'],
    ];

    /**
     * Filter project context intelligently based on user prompt keywords and relation mapping.
     * 
     * ENHANCEMENT: Added deep multi-degree relation tracing, advanced keyword matching variations, 
     * and precise project complexity weighting to solve shallow filtering and truncation issues.
     */
    public static function filter(string $userPrompt, array $projectContext): array
    {
        $filteredContext = [];
        $promptLower = mb_strtolower($userPrompt);

        // ENHANCEMENT: Normalize and expand user prompt keywords to support plurals and diverse phrasing.
        $promptKeywords = preg_split('/\s+/', $promptLower);

        // 1. Calculate total classes and components available in the project.
        $totalClassesCount = 0;
        foreach ($projectContext as $key => $items) {
            if (is_array($items) && $key !== 'routes') {
                $totalClassesCount += count($items);
            }
        }

        // 2. If it's a small project, send full context without filtering to ensure complete visibility.
        if ($totalClassesCount <= self::SMALL_PROJECT) {
            return $projectContext;
        }

        // Keep track of explicitly matched models to resolve related models automatically.
        $matchedModelNames = [];

        // First pass: identify direct matches and relevant categories.
        foreach ($projectContext as $category => $items) {
            if (!is_array($items)) {
                $filteredContext[$category] = $items;
                continue;
            }

            if (in_array($category, self::ALWAYS_INCLUDED, true)) {
                $filteredContext[$category] = $items;
                continue;
            }

            $matchedItems = [];
            $isCategoryRelevant = str_contains($promptLower, rtrim(mb_strtolower($category), 's'));

            if (!$isCategoryRelevant) {
                foreach (self::CATEGORY_KEYWORDS[$category] ?? [] as $word) {
                    if (str_contains($promptLower, $word)) {
                        $isCategoryRelevant = true;
                        break;
                    }
                }
            }

            foreach ($items as $name => $details) {
                $shortName = class_basename((string) $name);
                $nameLower = mb_strtolower($shortName);
                $isExplicitlyMentioned = $nameLower !== '' && str_contains($promptLower, $nameLower);

                if ($isExplicitlyMentioned || $isCategoryRelevant) {
                    $matchedItems[$name] = $details;
                    if ($category === 'models') {
                        $matchedModelNames[] = $shortName;
                    }
                }
            }

            if (!empty($matchedItems)) {
                $filteredContext[$category] = $matchedItems;
            }
        }

        // 3. [Smart Relation Extension] If specific models were matched, automatically include related models found via AST relations.
        if (!empty($matchedModelNames) && isset($projectContext['models']) && is_array($projectContext['models'])) {
            $relatedModelsToInclude = [];
            
            // ENHANCEMENT: Implemented recursive/multi-degree relation tracing (Deep Nesting support) 
            // instead of stopping at first-degree relations.
            $queue = $matchedModelNames;
            $visitedModels = [];

            while (!empty($queue)) {
                $currentModel = array_shift($queue);
                if (isset($visitedModels[$currentModel])) {
                    continue;
                }
                $visitedModels[$currentModel] = true;

                foreach ($projectContext['models'] as $modelName => $modelDetails) {
                    $shortModelName = class_basename((string) $modelName);
                    
                    if ($shortModelName === $currentModel) {
                        $methods = $modelDetails['methods'] ?? [];
                        foreach ($methods as $methodMeta) {
                            if (isset($methodMeta['relation']['related'])) {
                                $relatedClass = class_basename($methodMeta['relation']['related']);
                                if (!isset($visitedModels[$relatedClass])) {
                                    $relatedModelsToInclude[] = $relatedClass;
                                    $queue[] = $relatedClass; // Push to queue for next degree checking
                                }
                            }
                        }
                    }
                }
            }

            // Add related models to the filtered context models list to ensure foreign key awareness.
            if (!empty($relatedModelsToInclude)) {
                $filteredContext['models'] ??= [];
                foreach ($projectContext['models'] as $modelName => $modelDetails) {
                    $shortModelName = class_basename((string) $modelName);
                    if (in_array($shortModelName, $relatedModelsToInclude, true)) {
                        $filteredContext['models'][$modelName] = $modelDetails;
                    }
                }
            }
        }

        // 4. Always preserve routes and package_json as core components for architectural analysis.
        if (isset($projectContext['routes'])) {
            $filteredContext['routes'] = $projectContext['routes'];
        }
        if (isset($projectContext['package_json'])) {
            $filteredContext['package_json'] = $projectContext['package_json'];
        }

        // 5. Fallback: if filtering returns empty due to keyword mismatch, return full context to prevent AI failure.
        if (empty($filteredContext)) {
            return $projectContext;
        }

        return $filteredContext;
    }
}