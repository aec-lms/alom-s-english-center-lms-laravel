<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Appearance\SiteLayoutManager;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SiteLayoutController extends Controller
{
    public function index(SiteLayoutManager $layouts): View
    {
        return view('admin.site-layout.index',[
            'header'=>$layouts->header(),
            'footer'=>$layouts->footer(),
        ]);
    }

    public function update(Request $request,SiteLayoutManager $layouts): RedirectResponse
    {
        $validated=$request->validate([
            'header.style'=>['required','in:standard,centered,compact'],
            'header.sticky'=>['required','in:0,1'],
            'header.sections'=>['required','array','size:3'],
            'header.sections.*.key'=>['required','in:brand,navigation,actions'],
            'header.sections.*.visible'=>['required','in:0,1'],
            'header.sections.*.order'=>['required','integer','between:1,100'],
            'footer.columns'=>['required','integer','in:2,3,4'],
            'footer.show_payment_strip'=>['required','in:0,1'],
            'footer.sections'=>['required','array','size:4'],
            'footer.sections.*.key'=>['required','in:brand,links,company,contact'],
            'footer.sections.*.visible'=>['required','in:0,1'],
            'footer.sections.*.order'=>['required','integer','between:1,100'],
        ]);
        DB::transaction(function()use($layouts,$validated,$request):void{
            $layouts->save($validated['header'],$validated['footer'],auth()->id());
            AuditLog::query()->create([
                'user_id'=>auth()->id(),'action'=>'site.layout.updated','entity_type'=>'site_layout',
                'entity_id'=>'header_and_footer','ip_address'=>$request->ip(),
                'metadata'=>[
                    'header_style'=>$validated['header']['style'],
                    'header_visible'=>array_values(array_map(static fn($s)=>$s['key'],array_filter($validated['header']['sections'],static fn($s)=>$s['visible']==='1'))),
                    'footer_visible'=>array_values(array_map(static fn($s)=>$s['key'],array_filter($validated['footer']['sections'],static fn($s)=>$s['visible']==='1'))),
                ],
            ]);
        });
        return redirect()->route('admin.site-layout.index')->with('status','Header and footer layout saved successfully.');
    }
}
