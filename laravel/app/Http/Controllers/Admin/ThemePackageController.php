<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Appearance\ThemePackageManager;
use App\Core\Security\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ThemePackageController extends Controller
{
    public function index(ThemePackageManager $themes): View
    {
        return view('admin.appearance.themes',[
            'themes'=>$themes->installed(),
            'activeSlug'=>$themes->activeSlug(),
            'rollbackAvailable'=>array_fill_keys(array_keys($themes->installed()),false),
        ]);
    }

    public function upload(Request $request,ThemePackageManager $themes,AuditLogger $audit): RedirectResponse
    {
        $validated=$request->validate(['theme_zip'=>['required','file','mimes:zip','max:10240']]);
        try{
            $theme=$themes->install($validated['theme_zip'],auth()->id());
            $audit->log('appearance.theme_package_installed','theme',$theme['slug'],['version'=>$theme['version']]);
            return back()->with('status',"Theme '{$theme['name']}' v{$theme['version']} installed.");
        }catch(Throwable $e){return back()->withErrors(['theme'=>$e->getMessage()]);}
    }

    public function preview(string $slug,ThemePackageManager $themes): View
    {
        try {
            $theme=$themes->manifest($slug);
        } catch (Throwable $e) {
            abort(404,'Theme preview is unavailable.');
        }

        return view('admin.appearance.theme-preview',compact('theme'));
    }

    public function rollback(string $slug,ThemePackageManager $themes,AuditLogger $audit): RedirectResponse
    {
        try {
            $theme=$themes->rollback($slug,auth()->id());
            $audit->log('appearance.theme_rolled_back','theme',$slug,['version'=>$theme['version']]);
            return back()->with('status',"Theme '{$slug}' rolled back to v{$theme['version']}.");
        } catch (Throwable $e) {
            return back()->withErrors(['theme'=>$e->getMessage()]);
        }
    }

    public function activate(string $slug,ThemePackageManager $themes,AuditLogger $audit): RedirectResponse
    {
        try{
            $themes->activate($slug,auth()->id());
            $audit->log('appearance.theme_activated','theme',$slug,[]);
            return back()->with('status',"Theme '{$slug}' activated.");
        }catch(Throwable $e){return back()->withErrors(['theme'=>$e->getMessage()]);}
    }

    public function deactivate(ThemePackageManager $themes,AuditLogger $audit): RedirectResponse
    {
        try{
            $slug=$themes->activeSlug();
            $themes->deactivate(auth()->id());
            if($slug!==null)$audit->log('appearance.theme_deactivated','theme',$slug,[]);
            return back()->with('status','Active theme deactivated. Previous appearance settings restored.');
        }catch(Throwable $e){return back()->withErrors(['theme'=>$e->getMessage()]);}
    }

    public function uninstall(string $slug,ThemePackageManager $themes,AuditLogger $audit): RedirectResponse
    {
        try{
            $themes->uninstall($slug,auth()->id());
            $audit->log('appearance.theme_package_uninstalled','theme',$slug,[]);
            return back()->with('status',"Theme '{$slug}' uninstalled.");
        }catch(Throwable $e){return back()->withErrors(['theme'=>$e->getMessage()]);}
    }
}
