<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

/**
 * 文档读取：把 docs 目录下的 Markdown 一次性返回给前端渲染。
 * 只读取 docs 根目录内的 .md 文件，不接受外部路径。
 */
final class DocsController
{
    public function index(array $params, Request $request): void
    {
        $dir = dirname(__DIR__, 2) . '/docs';
        $docs = [];
        if (is_dir($dir)) {
            $names = scandir($dir) ?: [];
            natcasesort($names);
            foreach ($names as $name) {
                if (!is_string($name) || !str_ends_with(strtolower($name), '.md')) {
                    continue;
                }
                $path = $dir . '/' . $name;
                if (!is_file($path)) {
                    continue;
                }
                $content = (string) file_get_contents($path);
                $lower = strtolower($name);
                $english = str_ends_with($lower, '.en.md');
                $base = $english ? (string) preg_replace('/\.en\.md$/i', '', $name) : (string) preg_replace('/\.md$/i', '', $name);
                $docs[] = [
                    'name' => $name,
                    'base' => $base,
                    'lang' => $english ? 'en-US' : 'zh-CN',
                    'title' => self::title($content, $name),
                    'content' => $content,
                ];
            }
        }
        Response::json(['docs' => $docs]);
    }

    /** 用正文第一个一级标题作标题，缺失时回退到文件名 */
    private static function title(string $content, string $name): string
    {
        if (preg_match('/^\s*#\s+(.+)$/m', $content, $m) === 1) {
            return trim($m[1]);
        }
        return preg_replace('/\.md$/i', '', $name) ?? $name;
    }
}
