<?php
declare(strict_types=1);

namespace App\Llm;

use RuntimeException;

abstract class AbstractLlmClient
{
    abstract public function identity(): array;
    abstract public function generateJson(string $prompt, int $maxTokens = 200): string;
    abstract protected function generateRawResponse(string $prompt): string;

    public function generateSql(string $prompt): string
    {
        $rawResponse = $this->generateRawResponse($prompt);
        return $this->extractSql($rawResponse);
    }

    /**
     * Shared SQL extraction logic with multiple fallback strategies
     */
    protected function extractSql(string $response): string
    {
        $original = $response;
        
        // Step 1: Try to extract from complete markdown code blocks
        if (preg_match('/```(?:sql)?\s*(SELECT\s+.*?)\s*```/is', $response, $matches)) {
            return $this->cleanSql($matches[1]);
        }
        
        // Step 2: Handle incomplete markdown (missing closing backticks)
        if (preg_match('/```(?:sql)?\s*(SELECT\s+.*)/is', $response, $matches)) {
            return $this->cleanSql($matches[1]);
        }
        
        // Step 3: Remove any markdown and common prefixes, then validate
        $cleaned = preg_replace('/```(?:sql)?\s*([\s\S]*?)\s*```/i', '$1', $response);
        $cleaned = preg_replace('/^(MySQL compatible SELECT statement:?|SQL:?|Query:?)\s*/i', '', $cleaned);
        $cleaned = trim($cleaned);
        
        if (preg_match('/^\s*SELECT\s+.+?\s+FROM\s+/is', $cleaned)) {
            return $this->cleanSql($cleaned);
        }
        
        // Step 4: Look for complete SELECT statement anywhere in response
        if (preg_match('/SELECT\s+.*?FROM\s+\w+(?:\s+(?:WHERE|GROUP BY|ORDER BY|LIMIT|HAVING)\s+.*?)*(?=\s*$|\s*[;.]|\s*```)/is', $response, $matches)) {
            return $this->cleanSql($matches[0]);
        }
        
        // Step 5: Simple fallback - find basic SELECT FROM pattern
        if (preg_match('/SELECT\s+.*?FROM\s+\w+/is', $response, $matches)) {
            return $this->cleanSql($matches[0]);
        }
        
        throw new RuntimeException('No valid SQL in LLM response: ' . $original);
    }

    /**
     * Clean and normalize SQL string
     */
    private function cleanSql(string $sql): string
    {
        $sql = trim($sql);
        $sql = rtrim($sql, ';.,');
        $sql = preg_replace('/\s+/', ' ', $sql);
        return $sql;
    }
}