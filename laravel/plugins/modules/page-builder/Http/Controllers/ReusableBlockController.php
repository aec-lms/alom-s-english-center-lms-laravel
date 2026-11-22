<?php

declare(strict_types=1);

namespace Plugins\\Modules\\PageBuilder\\Http\\Controllers;

use App\\Core\\Security\\AuditLogger;
use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\View\\View;
use Plugins\\Modules\\PageBuilder\\Services\\ReusableBlockLibrary;

final class ReusableBlockController extends Controller
{
    public function index(ReusableBlockLibrary $library): View
    {
        return view('page-builder::reusable-blocks', ['blocks' => $library->all()]);
    }

    public function store(Request $request, ReusableBlockLibrary $library, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:heading,text,button'],
            'title' => ['required', 'string', 'max:80'],
            'content' => ['required', 'string', 'max:12000'],
            'url' => ['nullable', 'string', 'max:2048', 'regex:#^(https?://[^\\s]+|/(?!/)[^\\s]*)$#i'],
        ]);
        if ($data['type'] === 'button' && trim((string) ($data['url'] ?? '')) === '') {
            return back()->withErrors(['url' => 'A button block requires a destination URL.'])->withInput();
        }
        $block = $library->save($data['type'], $data['title'], $data['content'], $data['url'] ?? null, auth()->id());
        $audit->log('page_builder.reusable_block_created', 'reusable_block', $block['id'], ['type' => $block['type']]);
        return back()->with('status', 'Reusable block saved to library.');
    }

    public function destroy(string $id, ReusableBlockLibrary $library, AuditLogger $audit): RedirectResponse
    {
        if (!$library->delete($id, auth()->id())) return back()->withErrors(['block' => 'Reusable block was not found.']);
        $audit->log('page_builder.reusable_block_deleted', 'reusable_block', $id, []);
        return back()->with('status', 'Reusable block removed from library.');
    }
}
